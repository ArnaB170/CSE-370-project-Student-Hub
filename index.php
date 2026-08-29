<?php
// ============================================================
// index.php — Secure Google OAuth Login (G Suite Only)
// ============================================================
session_start();
require_once 'db_config.php';

// ────────────────────────────────────────────────────────────
// CONFIGURATION — Update these values before deployment
// ────────────────────────────────────────────────────────────
define('GOOGLE_CLIENT_ID', '514210034220-9r7j6tqbkl5dmaepaija7aib7npoq08a.apps.googleusercontent.com');
define('ALLOWED_DOMAINS',  ['g.bracu.ac.bd']); // BRAC University G Suite domain

// ────────────────────────────────────────────────────────────
// BACKEND: Logout handler
// ────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

// ────────────────────────────────────────────────────────────
// BACKEND: Google ID-token verification (POST from GIS callback)
// ────────────────────────────────────────────────────────────
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['credential'])) {
    $id_token = $_POST['credential'];

    // Call Google's tokeninfo endpoint to verify the JWT
    $verify_url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token);

    $ch = curl_init($verify_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        $error_message = 'Network error during verification. Please try again.';
    } elseif ($http_code !== 200) {
        $error_message = 'Token verification failed. The token may be expired or invalid.';
    } else {
        $payload = json_decode($response, true);

        // 1. Verify audience matches our Client ID
        if (!isset($payload['aud']) || $payload['aud'] !== GOOGLE_CLIENT_ID) {
            $error_message = 'Verification failed: token audience mismatch.';
        }
        // 2. Reject personal Gmail / consumer Google accounts (no 'hd' claim)
        elseif (!isset($payload['hd'])) {
            $error_message = 'Access Denied — Personal Gmail accounts (@gmail.com) are not permitted. You must sign in with an authorized organizational (G Suite) email address.';
        }
        // 3. Reject unauthorized hosted domains
        elseif (!in_array($payload['hd'], ALLOWED_DOMAINS, true)) {
            $error_message = 'Access Denied — The domain "@' . htmlspecialchars($payload['hd'], ENT_QUOTES, 'UTF-8') . '" is not authorized for this application. Contact your system administrator.';
        }
        // 4. All checks passed — create authenticated session
        else {
            $_SESSION['authenticated'] = true;
            $_SESSION['user'] = [
                'email'   => $payload['email']   ?? '',
                'name'    => $payload['name']    ?? '',
                'picture' => $payload['picture'] ?? '',
                'domain'  => $payload['hd'],
            ];
            // POST-Redirect-GET to prevent form resubmission
            header('Location: index.php');
            exit;
        }
    }
}

$is_authenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
$user = $_SESSION['user'] ?? null;

// ────────────────────────────────────────────────────────────
// BACKEND: Redirect new users to onboarding if no profile exists
// ────────────────────────────────────────────────────────────
if ($is_authenticated && $user && !isset($_SESSION['profile_id'])) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$conn->connect_error) {
        $stmt = $conn->prepare('SELECT profile_id FROM Student WHERE email = ?');
        $stmt->bind_param('s', $user['email']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $_SESSION['profile_id'] = $result->fetch_assoc()['profile_id'];
            $stmt->close();
            $conn->close();
            header('Location: services.php');
            exit;
        } else {
            $stmt->close();
            $conn->close();
            header('Location: onboarding.php');
            exit;
        }
        $stmt->close();
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Secure organizational login portal — authorized G Suite accounts only.">
    <title>Sign In — Secure Portal</title>

    <!-- Google Fonts — Gothic serif + clean sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">

    <style>
        /* ── Reset & Base ─────────────────────────────── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg-deepest:    #000000;
            --bg-dark:       #080808;
            --bg-card:       #0a0a0a;
            --bg-card-hover: #111111;
            --crimson:       #8B0000;
            --crimson-light: #a01020;
            --crimson-glow:  rgba(139, 0, 0, 0.35);
            --crimson-soft:  rgba(139, 0, 0, 0.12);
            --text-primary:  #e8e0d8;
            --text-secondary:#8a827a;
            --text-muted:    #5a5550;
            --border-dark:   #1a1818;
            --border-crimson:rgba(139, 0, 0, 0.3);
            --font-gothic:   'Cinzel', 'Garamond', serif;
            --font-body:     'Inter', system-ui, sans-serif;
        }

        html { height: 100%; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-body);
            color: var(--text-primary);
            background:
                radial-gradient(ellipse 80% 60% at 50% 0%, rgba(139,0,0,0.06) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 50% 100%, rgba(139,0,0,0.04) 0%, transparent 50%),
                linear-gradient(180deg, var(--bg-deepest) 0%, #060606 50%, var(--bg-dark) 100%);
            overflow: hidden;
        }

        /* ── Ambient animated particles ───────────────── */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image:
                radial-gradient(1px 1px at 15% 25%, rgba(139,0,0,0.25) 0%, transparent 100%),
                radial-gradient(1px 1px at 85% 15%, rgba(139,0,0,0.2)  0%, transparent 100%),
                radial-gradient(1px 1px at 45% 80%, rgba(139,0,0,0.15) 0%, transparent 100%),
                radial-gradient(1.5px 1.5px at 70% 60%, rgba(200,180,160,0.08) 0%, transparent 100%),
                radial-gradient(1px 1px at 25% 65%, rgba(200,180,160,0.06) 0%, transparent 100%);
            animation: drift 20s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }

        @keyframes drift {
            0%   { transform: translateY(0)   scale(1);    opacity: 0.7; }
            50%  { transform: translateY(-8px) scale(1.02); opacity: 1;   }
            100% { transform: translateY(4px)  scale(0.98); opacity: 0.8; }
        }

        /* ── Login Card ───────────────────────────────── */
        .login-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            margin: 1rem;
            padding: 3rem 2.5rem;
            background: var(--bg-card);
            border: 1px solid var(--border-dark);
            border-radius: 4px;
            box-shadow:
                0 0 80px rgba(139,0,0,0.08),
                0 30px 60px rgba(0,0,0,0.5),
                inset 0 1px 0 rgba(255,255,255,0.02);
            animation: cardEnter 0.8s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        @keyframes cardEnter {
            0% { opacity: 0; transform: translateY(30px) scale(0.97); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Top crimson accent line */
        .login-card::before {
            content: '';
            position: absolute;
            top: 0; left: 50%;
            transform: translateX(-50%);
            width: 60%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--crimson), transparent);
            border-radius: 0 0 2px 2px;
        }

        /* ── Emblem / Crest ────────────────────────────── */
        .emblem {
            width: 64px; height: 64px;
            margin: 0 auto 1.75rem;
            display: flex; align-items: center; justify-content: center;
            border: 1.5px solid var(--border-crimson);
            border-radius: 50%;
            background: radial-gradient(circle, rgba(139,0,0,0.1) 0%, transparent 70%);
            animation: pulse-ring 4s ease-in-out infinite;
        }

        .emblem svg { width: 28px; height: 28px; fill: var(--crimson); }

        @keyframes pulse-ring {
            0%, 100% { box-shadow: 0 0 0 0 rgba(139,0,0,0.15); }
            50%      { box-shadow: 0 0 0 10px rgba(139,0,0,0); }
        }

        /* ── Typography ───────────────────────────────── */
        h1 {
            font-family: var(--font-gothic);
            font-size: 1.6rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-align: center;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .subtitle {
            text-align: center;
            font-size: 0.82rem;
            font-weight: 300;
            color: var(--text-secondary);
            letter-spacing: 0.04em;
            margin-bottom: 2.25rem;
            line-height: 1.6;
        }

        .subtitle strong {
            color: var(--crimson-light);
            font-weight: 500;
        }

        /* ── Divider ──────────────────────────────────── */
        .divider {
            display: flex; align-items: center; gap: 1rem;
            margin: 1.5rem 0;
            color: var(--text-muted);
            font-size: 0.7rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-dark), transparent);
        }

        /* ── Google Sign-In Button Container ──────────── */
        .oauth-container {
            display: flex;
            justify-content: center;
            margin: 0.5rem 0;
        }

        /* ── Error Alert ──────────────────────────────── */
        .error-alert {
            margin-bottom: 1.5rem;
            padding: 1rem 1.25rem;
            background: rgba(139, 0, 0, 0.08);
            border: 1px solid rgba(139, 0, 0, 0.25);
            border-left: 3px solid var(--crimson);
            border-radius: 3px;
            animation: shake 0.4s ease-in-out, fadeIn 0.5s ease;
        }

        .error-alert p {
            font-size: 0.82rem;
            line-height: 1.6;
            color: #d4a0a0;
        }

        .error-alert .error-title {
            display: flex; align-items: center; gap: 0.5rem;
            font-family: var(--font-gothic);
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            color: var(--crimson-light);
            margin-bottom: 0.4rem;
            text-transform: uppercase;
        }

        .error-alert .error-title svg {
            width: 16px; height: 16px; fill: var(--crimson-light);
            flex-shrink: 0;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%      { transform: translateX(-6px); }
            40%      { transform: translateX(5px); }
            60%      { transform: translateX(-3px); }
            80%      { transform: translateX(2px); }
        }

        @keyframes fadeIn {
            0% { opacity: 0; transform: translateY(-8px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* ── Footer / Policy ──────────────────────────── */
        .card-footer {
            margin-top: 2rem;
            padding-top: 1.25rem;
            border-top: 1px solid var(--border-dark);
            text-align: center;
            font-size: 0.7rem;
            color: var(--text-muted);
            line-height: 1.7;
            letter-spacing: 0.02em;
        }

        /* ── Dashboard (Authenticated State) ──────────── */
        .dashboard-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 480px;
            margin: 1rem;
            padding: 2.5rem;
            background: var(--bg-card);
            border: 1px solid var(--border-dark);
            border-radius: 4px;
            box-shadow:
                0 0 60px rgba(139,0,0,0.06),
                0 20px 40px rgba(0,0,0,0.5);
            animation: cardEnter 0.8s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0; left: 50%;
            transform: translateX(-50%);
            width: 80%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--crimson), transparent);
        }

        .user-header {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .user-avatar {
            width: 56px; height: 56px;
            border-radius: 50%;
            border: 2px solid var(--border-crimson);
            object-fit: cover;
            background: var(--bg-dark);
        }

        .user-info h2 {
            font-family: var(--font-gothic);
            font-size: 1.15rem;
            font-weight: 600;
            letter-spacing: 0.04em;
        }

        .user-info .user-email {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 0.2rem;
        }

        .user-info .user-domain {
            display: inline-block;
            margin-top: 0.35rem;
            padding: 0.15rem 0.6rem;
            font-size: 0.65rem;
            font-weight: 500;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--crimson-light);
            background: var(--crimson-soft);
            border: 1px solid rgba(139,0,0,0.2);
            border-radius: 2px;
        }

        .session-details {
            padding: 1rem 1.25rem;
            background: rgba(255,255,255,0.015);
            border: 1px solid var(--border-dark);
            border-radius: 3px;
            margin-bottom: 1.5rem;
        }

        .session-details dt {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
            margin-bottom: 0.2rem;
        }

        .session-details dd {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }

        .session-details dd:last-child { margin-bottom: 0; }

        .btn-logout {
            display: block;
            width: 100%;
            padding: 0.8rem;
            font-family: var(--font-gothic);
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            text-align: center;
            color: var(--text-primary);
            background: transparent;
            border: 1px solid var(--border-crimson);
            border-radius: 3px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-logout:hover {
            background: rgba(139,0,0,0.12);
            border-color: var(--crimson);
            box-shadow: 0 0 20px rgba(139,0,0,0.15);
        }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 480px) {
            .login-card, .dashboard-card { padding: 2rem 1.5rem; }
            h1 { font-size: 1.35rem; }
        }
    </style>
</head>
<body>

<?php if ($is_authenticated && $user): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- AUTHENTICATED: Dashboard View                          -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="dashboard-card" role="main">
    <div class="user-header">
        <?php if (!empty($user['picture'])): ?>
            <img class="user-avatar"
                 src="<?= htmlspecialchars($user['picture'], ENT_QUOTES, 'UTF-8') ?>"
                 alt="Profile"
                 referrerpolicy="no-referrer">
        <?php else: ?>
            <div class="user-avatar" style="display:flex;align-items:center;justify-content:center;">
                <svg viewBox="0 0 24 24" width="28" height="28" fill="#5a5550"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
            </div>
        <?php endif; ?>
        <div class="user-info">
            <h2><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="user-email"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></div>
            <span class="user-domain"><?= htmlspecialchars($user['domain'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>

    <dl class="session-details">
        <dt>Status</dt>
        <dd style="color:#4a9;">&#9679; Authenticated</dd>
        <dt>Session Started</dt>
        <dd><?= date('M j, Y — g:i A') ?></dd>
    </dl>

    <a href="index.php?action=logout" class="btn-logout" id="btn-logout">End Session</a>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- UNAUTHENTICATED: Login View                            -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="login-card" role="main">

    <!-- Emblem -->
    <div class="emblem" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 2.18l7 3.12v4.7c0 4.67-3.13 9.06-7 10.2-3.87-1.14-7-5.53-7-10.2V6.3l7-3.12zM11 7v6h2V7h-2zm0 8v2h2v-2h-2z"/></svg>
    </div>

    <h1>Secure Portal</h1>
    <p class="subtitle">
        Authorized <strong>organizational accounts</strong> only.<br>
        Sign in with your institutional G&nbsp;Suite credentials.
    </p>

    <!-- Error Display -->
    <?php if (!empty($error_message)): ?>
    <div class="error-alert" role="alert" id="error-alert">
        <div class="error-title">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            Authentication Denied
        </div>
        <p><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <?php endif; ?>

    <div class="divider">Sign in with Google</div>

    <!-- Google Sign-In Button (rendered by GIS library) -->
    <div class="oauth-container">
        <div id="g_id_onload"
             data-client_id="<?= htmlspecialchars(GOOGLE_CLIENT_ID, ENT_QUOTES, 'UTF-8') ?>"
             data-login_uri="<?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>"
             data-auto_prompt="false"
             data-context="signin"
             data-ux_mode="redirect">
        </div>
        <div class="g_id_signin"
             data-type="standard"
             data-shape="rectangular"
             data-theme="filled_black"
             data-text="signin_with"
             data-size="large"
             data-logo_alignment="left"
             data-width="320">
        </div>
    </div>

    <div class="card-footer">
        <p>Only verified G&nbsp;Suite organizational accounts are accepted.<br>
        Personal @gmail.com addresses will be denied access.</p>
    </div>
</div>

<!-- Google Identity Services Library -->
<script src="https://accounts.google.com/gsi/client" async defer></script>

<?php endif; ?>

</body>
</html>
