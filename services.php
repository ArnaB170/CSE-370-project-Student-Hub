<?php
// ============================================================
// services.php — Main Services Dashboard
// ============================================================
session_start();
require_once 'db_config.php';

// ────────────────────────────────────────────────────────────
// GUARD: Redirect unauthenticated visitors to login
// ────────────────────────────────────────────────────────────
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}
if (!isset($_SESSION['profile_id'])) {
    header('Location: onboarding.php');
    exit;
}

// ────────────────────────────────────────────────────────────
// BACKEND: Fetch student profile from database (prepared stmt)
// ────────────────────────────────────────────────────────────
$student = null;
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if (!$conn->connect_error) {
    $stmt = $conn->prepare(
        'SELECT profile_id, id, email, name, dept FROM Student WHERE profile_id = ?'
    );
    $stmt->bind_param('i', $_SESSION['profile_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
    }
    $stmt->close();
    $conn->close();
}

// Fallback to session data if DB query fails
$display_name  = $student['name']  ?? $_SESSION['user']['name']  ?? 'Student';
$display_email = $student['email'] ?? $_SESSION['user']['email'] ?? '';
$display_dept  = $student['dept']  ?? '';
$display_sid   = $student['id']    ?? '';
$avatar_url    = $_SESSION['user']['picture'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Access your services — The Stranger, Study Hub, and Favor.">
    <title>Services — Secure Portal</title>

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
            --bg-card-hover: #0e0e0e;
            --crimson:       #8B0000;
            --crimson-light: #a01020;
            --crimson-mid:   #7a0a0a;
            --crimson-glow:  rgba(139, 0, 0, 0.35);
            --crimson-soft:  rgba(139, 0, 0, 0.10);
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
            font-family: var(--font-body);
            color: var(--text-primary);
            background:
                radial-gradient(ellipse 80% 50% at 50% 0%, rgba(139,0,0,0.05) 0%, transparent 60%),
                radial-gradient(ellipse 50% 40% at 80% 100%, rgba(139,0,0,0.04) 0%, transparent 50%),
                radial-gradient(ellipse 40% 35% at 20% 90%, rgba(139,0,0,0.03) 0%, transparent 50%),
                linear-gradient(180deg, var(--bg-deepest) 0%, #050505 40%, var(--bg-dark) 100%);
            background-attachment: fixed;
        }

        /* ── Ambient particles ────────────────────────── */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image:
                radial-gradient(1px 1px at 10% 20%, rgba(139,0,0,0.2)  0%, transparent 100%),
                radial-gradient(1px 1px at 90% 10%, rgba(139,0,0,0.18) 0%, transparent 100%),
                radial-gradient(1px 1px at 50% 85%, rgba(139,0,0,0.12) 0%, transparent 100%),
                radial-gradient(1px 1px at 75% 55%, rgba(200,180,160,0.06) 0%, transparent 100%),
                radial-gradient(1px 1px at 30% 70%, rgba(200,180,160,0.05) 0%, transparent 100%),
                radial-gradient(0.5px 0.5px at 60% 30%, rgba(139,0,0,0.15) 0%, transparent 100%);
            animation: drift 25s ease-in-out infinite alternate;
            pointer-events: none;
            z-index: 0;
        }

        @keyframes drift {
            0%   { transform: translateY(0)   scale(1);    opacity: 0.6; }
            50%  { transform: translateY(-6px) scale(1.01); opacity: 1;   }
            100% { transform: translateY(3px)  scale(0.99); opacity: 0.7; }
        }

        /* ── Top Navigation Bar ───────────────────────── */
        .top-bar {
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 2.5rem;
            background: rgba(5, 5, 5, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-dark);
            box-shadow: 0 4px 30px rgba(0,0,0,0.4);
        }

        .top-bar-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .top-bar-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            border: 1.5px solid var(--border-crimson);
            object-fit: cover;
            background: var(--bg-dark);
        }

        .top-bar-avatar-fallback {
            width: 42px; height: 42px;
            border-radius: 50%;
            border: 1.5px solid var(--border-crimson);
            background: var(--bg-card);
            display: flex; align-items: center; justify-content: center;
        }

        .top-bar-avatar-fallback svg {
            width: 22px; height: 22px; fill: var(--text-muted);
        }

        .top-bar-info h2 {
            font-family: var(--font-gothic);
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .top-bar-meta {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0.15rem;
        }

        .top-bar-meta span {
            font-size: 0.7rem;
            color: var(--text-secondary);
        }

        .top-bar-meta .sep {
            color: var(--border-dark);
            font-size: 0.6rem;
        }

        .badge-dept {
            display: inline-block;
            padding: 0.1rem 0.5rem;
            font-size: 0.6rem;
            font-weight: 500;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: var(--crimson-light);
            background: var(--crimson-soft);
            border: 1px solid rgba(139,0,0,0.18);
            border-radius: 2px;
        }

        .btn-end-session {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 1.2rem;
            font-family: var(--font-gothic);
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-primary);
            background: transparent;
            border: 1px solid var(--border-crimson);
            border-radius: 3px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-end-session svg {
            width: 15px; height: 15px; fill: currentColor;
        }

        .btn-end-session:hover {
            background: rgba(139,0,0,0.12);
            border-color: var(--crimson);
            box-shadow: 0 0 18px rgba(139,0,0,0.15);
        }

        /* ── Main Content ─────────────────────────────── */
        .main-content {
            position: relative;
            z-index: 1;
            max-width: 1100px;
            margin: 0 auto;
            padding: 3.5rem 2rem 4rem;
        }

        /* ── Section Header ───────────────────────────── */
        .section-header {
            text-align: center;
            margin-bottom: 3.5rem;
            animation: fadeUp 0.8s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .section-header h1 {
            font-family: var(--font-gothic);
            font-size: 1.8rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            color: var(--text-primary);
            margin-bottom: 0.6rem;
        }

        .section-header p {
            font-size: 0.88rem;
            font-weight: 300;
            color: var(--text-secondary);
            line-height: 1.7;
            max-width: 540px;
            margin: 0 auto;
        }

        @keyframes fadeUp {
            0%   { opacity: 0; transform: translateY(24px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* ── Service Cards Grid ───────────────────────── */
        .services-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.75rem;
        }

        /* ── Individual Service Card ──────────────────── */
        .service-card {
            position: relative;
            display: flex;
            flex-direction: column;
            padding: 2.25rem 2rem 2rem;
            background: var(--bg-card);
            border: 1px solid var(--border-dark);
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            overflow: hidden;
            transition:
                transform 0.4s cubic-bezier(0.22, 1, 0.36, 1),
                border-color 0.4s ease,
                box-shadow 0.5s ease;
            animation: cardReveal 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .service-card:nth-child(1) { animation-delay: 0.1s; }
        .service-card:nth-child(2) { animation-delay: 0.25s; }
        .service-card:nth-child(3) { animation-delay: 0.4s;  }

        @keyframes cardReveal {
            0%   { opacity: 0; transform: translateY(40px) scale(0.96); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* Top accent line */
        .service-card::before {
            content: '';
            position: absolute;
            top: 0; left: 50%;
            transform: translateX(-50%);
            width: 0%;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--crimson), transparent);
            transition: width 0.5s ease;
        }

        /* Ambient glow overlay */
        .service-card::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(ellipse 70% 50% at 50% 0%, rgba(139,0,0,0.04) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.5s ease;
            pointer-events: none;
        }

        .service-card:hover {
            transform: translateY(-6px);
            border-color: rgba(139,0,0,0.35);
            box-shadow:
                0 0 50px rgba(139,0,0,0.1),
                0 20px 50px rgba(0,0,0,0.35);
        }

        .service-card:hover::before { width: 70%; }
        .service-card:hover::after  { opacity: 1; }

        /* Card icon */
        .card-icon {
            width: 52px; height: 52px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid var(--border-dark);
            border-radius: 50%;
            background: radial-gradient(circle, rgba(139,0,0,0.06) 0%, transparent 70%);
            margin-bottom: 1.5rem;
            transition: border-color 0.4s ease, box-shadow 0.4s ease;
        }

        .card-icon svg {
            width: 24px; height: 24px;
            fill: var(--crimson);
            transition: fill 0.3s ease;
        }

        .service-card:hover .card-icon {
            border-color: var(--border-crimson);
            box-shadow: 0 0 20px rgba(139,0,0,0.12);
        }

        .service-card:hover .card-icon svg {
            fill: var(--crimson-light);
        }

        /* Card title */
        .card-title {
            font-family: var(--font-gothic);
            font-size: 1.15rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            transition: color 0.3s ease;
        }

        .service-card:hover .card-title {
            color: #f0e8e0;
        }

        /* Card description */
        .card-desc {
            font-size: 0.8rem;
            font-weight: 300;
            line-height: 1.75;
            color: var(--text-secondary);
            flex: 1;
            margin-bottom: 1.5rem;
        }

        /* Card footer (arrow indicator) */
        .card-action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 1rem;
            border-top: 1px solid var(--border-dark);
        }

        .card-action-label {
            font-size: 0.68rem;
            font-weight: 500;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-muted);
            transition: color 0.3s ease;
        }

        .service-card:hover .card-action-label {
            color: var(--crimson-light);
        }

        .card-arrow {
            width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid var(--border-dark);
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .card-arrow svg {
            width: 14px; height: 14px;
            fill: var(--text-muted);
            transition: fill 0.3s ease, transform 0.3s ease;
        }

        .service-card:hover .card-arrow {
            border-color: var(--border-crimson);
            background: var(--crimson-soft);
        }

        .service-card:hover .card-arrow svg {
            fill: var(--crimson-light);
            transform: translateX(2px);
        }

        /* ── Footer ───────────────────────────────────── */
        .page-footer {
            text-align: center;
            margin-top: 3rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(26,24,24,0.5);
            font-size: 0.68rem;
            color: var(--text-muted);
            letter-spacing: 0.03em;
            line-height: 1.7;
            animation: fadeUp 0.8s 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 900px) {
            .services-grid {
                grid-template-columns: 1fr;
                max-width: 480px;
                margin: 0 auto;
            }
        }

        @media (max-width: 600px) {
            .top-bar {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem 1.25rem;
                align-items: flex-start;
            }
            .btn-end-session { align-self: flex-end; }
            .main-content { padding: 2rem 1rem 3rem; }
            .section-header h1 { font-size: 1.4rem; }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TOP NAVIGATION BAR                                         -->
<!-- ═══════════════════════════════════════════════════════════ -->
<header class="top-bar">
    <div class="top-bar-left">
        <?php if (!empty($avatar_url)): ?>
            <img class="top-bar-avatar"
                 src="<?= htmlspecialchars($avatar_url, ENT_QUOTES, 'UTF-8') ?>"
                 alt="Avatar"
                 referrerpolicy="no-referrer">
        <?php else: ?>
            <div class="top-bar-avatar-fallback">
                <svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
            </div>
        <?php endif; ?>
        <div class="top-bar-info">
            <h2><?= htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="top-bar-meta">
                <span><?= htmlspecialchars($display_email, ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($display_dept): ?>
                    <span class="sep">•</span>
                    <span class="badge-dept"><?= htmlspecialchars($display_dept, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <?php if ($display_sid): ?>
                    <span class="sep">•</span>
                    <span>ID: <?= htmlspecialchars($display_sid, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <a href="index.php?action=logout" class="btn-end-session" id="btn-end-session">
        <svg viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
        End Session
    </a>
</header>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- MAIN CONTENT                                                -->
<!-- ═══════════════════════════════════════════════════════════ -->
<main class="main-content">

    <div class="section-header">
        <h1>Your Services</h1>
        <p>Welcome back, <strong><?= htmlspecialchars(explode(' ', $display_name)[0], ENT_QUOTES, 'UTF-8') ?></strong>.
        Choose a gateway below to begin.</p>
    </div>

    <!-- ── Three Service Cards ────────────────────────── -->
    <div class="services-grid">

        <!-- CARD 1 — The Stranger -->
        <a href="stranger.php" class="service-card" id="card-stranger">
            <div class="card-icon">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8 0-1.48.41-2.86 1.12-4.06.25.36.71.56 1.2.36.65-.27.88-1.01.64-1.67C7.68 5.64 9.71 4.5 12 4.5c.89 0 1.73.2 2.49.55-.07.24-.09.5-.04.76.14.72.78 1.25 1.5 1.25.05 0 .09 0 .14-.01C17.27 8.29 18 10.06 18 12c0 4.41-3.59 8-8 8zm-1-12.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zm4 0c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zm-5.33 6.5c.39.56.94 1 1.56 1.28.28.13.58.22.89.22h1.76c.31 0 .61-.09.89-.22.62-.28 1.17-.72 1.56-1.28H9.67z"/></svg>
            </div>
            <h3 class="card-title">The Stranger</h3>
            <p class="card-desc">
                Slip behind the veil of anonymity. Connect with fellow students you've never met,
                share unfiltered thoughts, and discover unexpected perspectives — all without
                revealing who you are.
            </p>
            <div class="card-action">
                <span class="card-action-label">Enter</span>
                <div class="card-arrow">
                    <svg viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                </div>
            </div>
        </a>

        <!-- CARD 2 — Study Hub -->
        <a href="study_hub.php" class="service-card" id="card-study-hub">
            <div class="card-icon">
                <svg viewBox="0 0 24 24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>
            </div>
            <h3 class="card-title">Study Hub</h3>
            <p class="card-desc">
                Your academic arsenal. Access curated resources, collaborate on coursework,
                exchange notes across departments, and forge study alliances that sharpen
                every edge of your knowledge.
            </p>
            <div class="card-action">
                <span class="card-action-label">Enter</span>
                <div class="card-arrow">
                    <svg viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                </div>
            </div>
        </a>

        <!-- CARD 3 — Favor -->
        <a href="favor.php" class="service-card" id="card-favor">
            <div class="card-icon">
                <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
            </div>
            <h3 class="card-title">Favor</h3>
            <p class="card-desc">
                One good turn deserves another. Request a helping hand or offer yours —
                from sharing notes to lending gear. Build a network of trust within
                your campus community.
            </p>
            <div class="card-action">
                <span class="card-action-label">Enter</span>
                <div class="card-arrow">
                    <svg viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>
                </div>
            </div>
        </a>

    </div>

    <div class="page-footer">
        All services are exclusive to verified organizational accounts.<br>
        Your session data is encrypted and never shared with third parties.
    </div>

</main>

</body>
</html>
