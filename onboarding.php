<?php
// ============================================================
// onboarding.php — Profile Creation for New G Suite Users
// ============================================================
session_start();
require_once 'db_config.php';

// ────────────────────────────────────────────────────────────
// GUARD: Must be authenticated via Google OAuth
// ────────────────────────────────────────────────────────────
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}

$user_email  = $_SESSION['user']['email']  ?? '';
$google_name = $_SESSION['user']['name']   ?? '';

// ────────────────────────────────────────────────────────────
// DATABASE CONNECTION
// ────────────────────────────────────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

// ────────────────────────────────────────────────────────────
// GUARD: If profile already exists, send back to dashboard
// ────────────────────────────────────────────────────────────
$check = $conn->prepare('SELECT profile_id FROM Student WHERE email = ?');
$check->bind_param('s', $user_email);
$check->execute();
$result = $check->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $_SESSION['profile_id'] = $row['profile_id'];
    $check->close();
    $conn->close();
    header('Location: index.php');
    exit;
}
$check->close();

// ────────────────────────────────────────────────────────────
// BACKEND: Handle form submission — INSERT with prepared stmts
// ────────────────────────────────────────────────────────────
$errors  = [];
$success = false;

// Preserve form values on validation failure
$form = [
    'student_id' => '',
    'name'       => $google_name,
    'dept'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect & sanitize inputs
    $student_id = trim($_POST['student_id']       ?? '');
    $name       = trim($_POST['name']             ?? '');
    $dept       = trim($_POST['dept']             ?? '');
    $password   = $_POST['password']              ?? '';
    $confirm    = $_POST['confirm_password']      ?? '';
    $mobiles    = $_POST['mobile']                ?? [];

    // Preserve values for re-render
    $form['student_id'] = $student_id;
    $form['name']       = $name;
    $form['dept']       = $dept;

    // ── Validation ──────────────────────────────────────────
    if ($student_id === '') {
        $errors[] = 'Student ID is required.';
    }
    if ($name === '') {
        $errors[] = 'Full name is required.';
    }
    if ($dept === '') {
        $errors[] = 'Department is required.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Filter out empty mobile entries
    $mobiles = array_values(array_filter(array_map('trim', $mobiles), fn($m) => $m !== ''));
    if (empty($mobiles)) {
        $errors[] = 'At least one mobile number is required.';
    }

    // ── Insert if no errors ─────────────────────────────────
    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_BCRYPT);

        $conn->begin_transaction();
        try {
            // INSERT into Student
            $stmt = $conn->prepare(
                'INSERT INTO Student (id, email, password, name, dept) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sssss', $student_id, $user_email, $hashed, $name, $dept);
            $stmt->execute();
            $profile_id = $conn->insert_id;
            $stmt->close();

            // INSERT into Student_Mobile (one row per number)
            $stmt = $conn->prepare(
                'INSERT INTO Student_Mobile (profile_id, mobile) VALUES (?, ?)'
            );
            foreach ($mobiles as $mobile) {
                $stmt->bind_param('is', $profile_id, $mobile);
                $stmt->execute();
            }
            $stmt->close();

            $conn->commit();

            // Store profile in session & redirect to services dashboard
            $_SESSION['profile_id'] = $profile_id;
            $conn->close();
            header('Location: services.php');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'A database error occurred. Please try again or contact support.';
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Complete your student profile to gain access to the secure portal.">
    <title>Complete Your Profile — Secure Portal</title>

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
            --bg-input:      #0d0d0d;
            --bg-input-focus:#111111;
            --crimson:       #8B0000;
            --crimson-light: #a01020;
            --crimson-glow:  rgba(139, 0, 0, 0.35);
            --crimson-soft:  rgba(139, 0, 0, 0.12);
            --text-primary:  #e8e0d8;
            --text-secondary:#8a827a;
            --text-muted:    #5a5550;
            --text-disabled: #3a3835;
            --border-dark:   #1a1818;
            --border-crimson:rgba(139, 0, 0, 0.3);
            --success:       #2a7a4a;
            --success-soft:  rgba(42,122,74,0.12);
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
            padding: 2rem 0;
        }

        /* ── Ambient particles ────────────────────────── */
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

        /* ── Card ─────────────────────────────────────── */
        .onboarding-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 520px;
            margin: 1rem;
            padding: 2.75rem 2.5rem 2.25rem;
            background: var(--bg-card);
            border: 1px solid var(--border-dark);
            border-radius: 4px;
            box-shadow:
                0 0 80px rgba(139,0,0,0.08),
                0 30px 60px rgba(0,0,0,0.5),
                inset 0 1px 0 rgba(255,255,255,0.02);
            animation: cardEnter 0.8s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .onboarding-card::before {
            content: '';
            position: absolute;
            top: 0; left: 50%;
            transform: translateX(-50%);
            width: 60%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--crimson), transparent);
            border-radius: 0 0 2px 2px;
        }

        @keyframes cardEnter {
            0%   { opacity: 0; transform: translateY(30px) scale(0.97); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Emblem ───────────────────────────────────── */
        .emblem {
            width: 56px; height: 56px;
            margin: 0 auto 1.5rem;
            display: flex; align-items: center; justify-content: center;
            border: 1.5px solid var(--border-crimson);
            border-radius: 50%;
            background: radial-gradient(circle, rgba(139,0,0,0.1) 0%, transparent 70%);
            animation: pulse-ring 4s ease-in-out infinite;
        }

        .emblem svg { width: 24px; height: 24px; fill: var(--crimson); }

        @keyframes pulse-ring {
            0%, 100% { box-shadow: 0 0 0 0 rgba(139,0,0,0.15); }
            50%      { box-shadow: 0 0 0 10px rgba(139,0,0,0); }
        }

        /* ── Typography ───────────────────────────────── */
        h1 {
            font-family: var(--font-gothic);
            font-size: 1.45rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-align: center;
            color: var(--text-primary);
            margin-bottom: 0.4rem;
        }

        .subtitle {
            text-align: center;
            font-size: 0.8rem;
            font-weight: 300;
            color: var(--text-secondary);
            letter-spacing: 0.03em;
            margin-bottom: 2rem;
            line-height: 1.6;
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

        .error-alert .error-title {
            display: flex; align-items: center; gap: 0.5rem;
            font-family: var(--font-gothic);
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            color: var(--crimson-light);
            margin-bottom: 0.5rem;
            text-transform: uppercase;
        }

        .error-alert .error-title svg {
            width: 16px; height: 16px; fill: var(--crimson-light); flex-shrink: 0;
        }

        .error-alert ul {
            list-style: none;
            padding: 0;
        }

        .error-alert li {
            font-size: 0.8rem;
            line-height: 1.7;
            color: #d4a0a0;
            padding-left: 1rem;
            position: relative;
        }

        .error-alert li::before {
            content: '—';
            position: absolute;
            left: 0;
            color: var(--crimson);
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%      { transform: translateX(-6px); }
            40%      { transform: translateX(5px); }
            60%      { transform: translateX(-3px); }
            80%      { transform: translateX(2px); }
        }

        @keyframes fadeIn {
            0%   { opacity: 0; transform: translateY(-8px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* ── Form Fields ──────────────────────────────── */
        .form-group {
            margin-bottom: 1.35rem;
        }

        .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-secondary);
            margin-bottom: 0.45rem;
        }

        .form-group label .required {
            color: var(--crimson-light);
            margin-left: 0.15rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem;
            font-family: var(--font-body);
            font-size: 0.88rem;
            color: var(--text-primary);
            background: var(--bg-input);
            border: 1px solid var(--border-dark);
            border-radius: 3px;
            outline: none;
            transition: border-color 0.3s ease, box-shadow 0.3s ease, background 0.3s ease;
        }

        .form-group input::placeholder {
            color: var(--text-muted);
            font-weight: 300;
        }

        .form-group input:focus {
            border-color: var(--crimson);
            background: var(--bg-input-focus);
            box-shadow: 0 0 0 3px rgba(139, 0, 0, 0.1), 0 0 20px rgba(139,0,0,0.06);
        }

        /* Read-only email field */
        .form-group input[readonly] {
            color: var(--text-muted);
            background: #060606;
            border-color: #141414;
            cursor: not-allowed;
            font-style: italic;
        }

        .form-group input[readonly]:focus {
            border-color: #141414;
            box-shadow: none;
        }

        .field-hint {
            font-size: 0.68rem;
            color: var(--text-muted);
            margin-top: 0.3rem;
            letter-spacing: 0.02em;
        }

        /* ── Row (side-by-side) ────────────────────────── */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* ── Mobile Number Group ──────────────────────── */
        .mobile-entry {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            margin-bottom: 0.5rem;
            animation: fadeIn 0.3s ease;
        }

        .mobile-entry input {
            flex: 1;
        }

        .btn-remove-mobile {
            width: 34px; height: 34px;
            display: flex; align-items: center; justify-content: center;
            background: transparent;
            border: 1px solid rgba(139,0,0,0.2);
            border-radius: 3px;
            cursor: pointer;
            color: var(--crimson);
            font-size: 1.1rem;
            font-weight: 600;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .btn-remove-mobile:hover {
            background: rgba(139,0,0,0.15);
            border-color: var(--crimson);
        }

        .btn-add-mobile {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.8rem;
            font-family: var(--font-body);
            font-size: 0.72rem;
            font-weight: 500;
            letter-spacing: 0.04em;
            color: var(--crimson-light);
            background: transparent;
            border: 1px dashed rgba(139,0,0,0.25);
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 0.25rem;
        }

        .btn-add-mobile:hover {
            background: rgba(139,0,0,0.08);
            border-color: var(--crimson);
        }

        .btn-add-mobile svg {
            width: 14px; height: 14px; fill: var(--crimson-light);
        }

        /* ── Divider ──────────────────────────────────── */
        .divider {
            display: flex; align-items: center; gap: 1rem;
            margin: 1.75rem 0 1.5rem;
            color: var(--text-muted);
            font-size: 0.65rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-dark), transparent);
        }

        /* ── Submit Button ────────────────────────────── */
        .btn-submit {
            display: block;
            width: 100%;
            padding: 0.9rem;
            margin-top: 0.5rem;
            font-family: var(--font-gothic);
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--text-primary);
            background: linear-gradient(135deg, var(--crimson) 0%, #6a0000 100%);
            border: 1px solid rgba(139,0,0,0.5);
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.06), transparent);
            transition: left 0.5s ease;
        }

        .btn-submit:hover {
            box-shadow: 0 0 30px rgba(139,0,0,0.3), 0 4px 15px rgba(0,0,0,0.3);
            transform: translateY(-1px);
        }

        .btn-submit:hover::before {
            left: 100%;
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        /* ── Footer ───────────────────────────────────── */
        .card-footer {
            margin-top: 1.75rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-dark);
            text-align: center;
            font-size: 0.68rem;
            color: var(--text-muted);
            line-height: 1.7;
        }

        /* ── Toggle password visibility ───────────────── */
        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 2.8rem;
        }

        .btn-toggle-pw {
            position: absolute;
            right: 0.6rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-toggle-pw svg {
            width: 18px; height: 18px;
            fill: var(--text-muted);
            transition: fill 0.2s ease;
        }

        .btn-toggle-pw:hover svg {
            fill: var(--text-secondary);
        }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 520px) {
            .onboarding-card { padding: 2rem 1.5rem; }
            .form-row { grid-template-columns: 1fr; }
            h1 { font-size: 1.25rem; }
        }
    </style>
</head>
<body>

<div class="onboarding-card" role="main">

    <!-- Emblem -->
    <div class="emblem" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>
    </div>

    <h1>Complete Your Profile</h1>
    <p class="subtitle">
        Your identity has been verified. Fill in the details below<br>
        to finalize your student profile.
    </p>

    <!-- ── Validation Errors ──────────────────────────── -->
    <?php if (!empty($errors)): ?>
    <div class="error-alert" role="alert">
        <div class="error-title">
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
            Validation Errors
        </div>
        <ul>
            <?php foreach ($errors as $err): ?>
                <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════ -->
    <!-- PROFILE FORM                                        -->
    <!-- ═══════════════════════════════════════════════════ -->
    <form method="POST" action="onboarding.php" autocomplete="off" novalidate>

        <!-- Email (read-only from Google session) -->
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email"
                   id="email"
                   value="<?= htmlspecialchars($user_email, ENT_QUOTES, 'UTF-8') ?>"
                   readonly
                   tabindex="-1">
            <div class="field-hint">Auto-filled from your Google account. This cannot be changed.</div>
        </div>

        <div class="divider">Academic Information</div>

        <!-- Student ID & Name -->
        <div class="form-row">
            <div class="form-group">
                <label for="student_id">Student ID <span class="required">*</span></label>
                <input type="text"
                       id="student_id"
                       name="student_id"
                       placeholder="e.g., 21301234"
                       value="<?= htmlspecialchars($form['student_id'], ENT_QUOTES, 'UTF-8') ?>"
                       required>
            </div>
            <div class="form-group">
                <label for="dept">Department <span class="required">*</span></label>
                <input type="text"
                       id="dept"
                       name="dept"
                       placeholder="e.g., Computer Science"
                       value="<?= htmlspecialchars($form['dept'], ENT_QUOTES, 'UTF-8') ?>"
                       required>
            </div>
        </div>

        <div class="form-group">
            <label for="name">Full Name <span class="required">*</span></label>
            <input type="text"
                   id="name"
                   name="name"
                   placeholder="e.g., John Doe"
                   value="<?= htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8') ?>"
                   required>
        </div>

        <div class="divider">Security</div>

        <!-- Password -->
        <div class="form-row">
            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <div class="password-wrapper">
                    <input type="password"
                           id="password"
                           name="password"
                           placeholder="Min. 8 characters"
                           minlength="8"
                           required>
                    <button type="button" class="btn-toggle-pw" onclick="togglePw('password', this)" aria-label="Toggle password visibility">
                        <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                <div class="password-wrapper">
                    <input type="password"
                           id="confirm_password"
                           name="confirm_password"
                           placeholder="Re-enter password"
                           minlength="8"
                           required>
                    <button type="button" class="btn-toggle-pw" onclick="togglePw('confirm_password', this)" aria-label="Toggle password visibility">
                        <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <div class="divider">Contact</div>

        <!-- Mobile Numbers (dynamic — multi-valued attribute) -->
        <div class="form-group">
            <label>Mobile Number(s) <span class="required">*</span></label>
            <div id="mobile-container">
                <div class="mobile-entry">
                    <input type="tel"
                           name="mobile[]"
                           placeholder="e.g., +8801XXXXXXXXX"
                           required>
                </div>
            </div>
            <button type="button" class="btn-add-mobile" id="btn-add-mobile" onclick="addMobileField()">
                <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                Add another number
            </button>
            <div class="field-hint">You may add multiple contact numbers.</div>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn-submit" id="btn-submit">Create Profile</button>
    </form>

    <div class="card-footer">
        Your password is hashed using bcrypt and stored securely.<br>
        Email address is linked to your verified Google account.
    </div>
</div>

<script>
    // ── Dynamic Mobile Number Fields ─────────────────────
    function addMobileField() {
        const container = document.getElementById('mobile-container');
        const entry = document.createElement('div');
        entry.className = 'mobile-entry';
        entry.innerHTML =
            '<input type="tel" name="mobile[]" placeholder="e.g., +8801XXXXXXXXX">' +
            '<button type="button" class="btn-remove-mobile" onclick="removeMobileField(this)" aria-label="Remove this number">&times;</button>';
        container.appendChild(entry);
        entry.querySelector('input').focus();
    }

    function removeMobileField(btn) {
        const entry = btn.closest('.mobile-entry');
        entry.style.opacity = '0';
        entry.style.transform = 'translateX(10px)';
        entry.style.transition = 'opacity 0.2s, transform 0.2s';
        setTimeout(() => entry.remove(), 200);
    }

    // ── Toggle Password Visibility ───────────────────────
    function togglePw(inputId, btn) {
        const input = document.getElementById(inputId);
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';

        // Swap icon
        btn.querySelector('svg').innerHTML = isPassword
            ? '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>'
            : '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
    }
</script>

</body>
</html>
