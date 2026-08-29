<?php
// ============================================================
// favor.php — Favor: Wallet Dashboard & Weekly Activity Log
// ============================================================
session_start();
require_once 'db_config.php';

// ────────────────────────────────────────────────────────────
// GUARD: Authenticated + profile required
// ────────────────────────────────────────────────────────────
if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
    header('Location: index.php');
    exit;
}
if (!isset($_SESSION['profile_id'])) {
    header('Location: onboarding.php');
    exit;
}

$profile_id = (int)$_SESSION['profile_id'];

// ────────────────────────────────────────────────────────────
// DATABASE CONNECTION
// ────────────────────────────────────────────────────────────
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('Database connection failed.');
}

// ── Fetch user name ──────────────────────────────────────
$user_name = $_SESSION['user']['name'] ?? 'Student';
$st = $conn->prepare('SELECT name FROM Student WHERE profile_id = ?');
$st->bind_param('i', $profile_id);
$st->execute();
$r = $st->get_result();
if ($r->num_rows > 0) $user_name = $r->fetch_assoc()['name'];
$st->close();

// ────────────────────────────────────────────────────────────
// POST HANDLERS
// ────────────────────────────────────────────────────────────
$wallet_errors = [];
$log_errors    = [];
$wallet_ok     = false;
$log_ok        = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── INITIALIZE WALLET ────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'init_wallet') {
        $w_name = trim($_POST['w_name'] ?? '');
        $w_type = trim($_POST['w_type'] ?? '');
        $w_num  = (int)($_POST['w_number'] ?? 0);
        $w_desc = trim($_POST['w_desc'] ?? '');

        if ($w_name === '') $wallet_errors[] = 'Wallet name is required.';
        if ($w_type === '') $wallet_errors[] = 'Wallet type is required.';

        if (empty($wallet_errors)) {
            $st = $conn->prepare(
                'INSERT INTO Favorwallet (student_id, name, type, number, description) VALUES (?, ?, ?, ?, ?)'
            );
            $st->bind_param('issis', $profile_id, $w_name, $w_type, $w_num, $w_desc);
            if ($st->execute()) {
                $wallet_ok = true;
            } else {
                $wallet_errors[] = 'Failed to create wallet. You may already have one.';
            }
            $st->close();
        }
    }

    // ── ADD WEEKLY LOG ENTRY ─────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'add_log') {
        $fw_id     = (int)($_POST['favorwallet_id'] ?? 0);
        $w_number  = (int)($_POST['weekly_number']  ?? 0);
        $a_record  = trim($_POST['activity_record'] ?? '');
        $a_count   = (int)($_POST['activity_count'] ?? 0);

        if ($fw_id <= 0)       $log_errors[] = 'Invalid wallet reference.';
        if ($a_record === '')  $log_errors[] = 'Activity record is required.';
        if ($w_number <= 0)    $log_errors[] = 'Weekly number must be greater than zero.';

        if (empty($log_errors)) {
            $st = $conn->prepare(
                'INSERT INTO Weekly_favor_log (favorwallet_id, weekly_number, activity_record, activity_count) VALUES (?, ?, ?, ?)'
            );
            $st->bind_param('iisi', $fw_id, $w_number, $a_record, $a_count);
            if ($st->execute()) {
                $log_ok = true;
            } else {
                $log_errors[] = 'Failed to add log entry. Please try again.';
            }
            $st->close();
        }
    }
}

// ────────────────────────────────────────────────────────────
// FETCH WALLET
// ────────────────────────────────────────────────────────────
$wallet = null;
$st = $conn->prepare('SELECT id, name, type, number, description FROM Favorwallet WHERE student_id = ?');
$st->bind_param('i', $profile_id);
$st->execute();
$res = $st->get_result();
if ($res->num_rows > 0) $wallet = $res->fetch_assoc();
$st->close();

// ────────────────────────────────────────────────────────────
// FETCH WEEKLY LOGS (if wallet exists)
// ────────────────────────────────────────────────────────────
$logs = [];
if ($wallet) {
    $wid = (int)$wallet['id'];
    $st = $conn->prepare(
        'SELECT id, weekly_number, activity_record, activity_count
         FROM Weekly_favor_log
         WHERE favorwallet_id = ?
         ORDER BY weekly_number DESC, id DESC'
    );
    $st->bind_param('i', $wid);
    $st->execute();
    $res = $st->get_result();
    while ($row = $res->fetch_assoc()) $logs[] = $row;
    $st->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Favor — Manage your favor wallet and weekly activity log.">
    <title>Favor — Wallet &amp; Activity Log</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">

    <style>
        /* ── Reset & Tokens ───────────────────────────── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --bg-deepest:     #000000;
            --bg-dark:        #060606;
            --bg-card:        #0a0a0a;
            --bg-input:       #0d0d0d;
            --bg-input-focus: #111111;
            --crimson:        #8B0000;
            --crimson-light:  #a01020;
            --crimson-soft:   rgba(139,0,0,0.10);
            --text-primary:   #e8e0d8;
            --text-secondary: #8a827a;
            --text-muted:     #5a5550;
            --border-dark:    #1a1818;
            --border-crimson: rgba(139,0,0,0.3);
            --success:        #2a7a4a;
            --success-soft:   rgba(42,122,74,0.08);
            --font-gothic:    'Cinzel', 'Garamond', serif;
            --font-body:      'Inter', system-ui, sans-serif;
        }

        html { height: 100%; }

        body {
            min-height: 100vh;
            font-family: var(--font-body);
            color: var(--text-primary);
            background:
                radial-gradient(ellipse 80% 50% at 50% 0%, rgba(139,0,0,0.04) 0%, transparent 60%),
                linear-gradient(180deg, var(--bg-deepest) 0%, var(--bg-dark) 100%);
            background-attachment: fixed;
        }

        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image:
                radial-gradient(1px 1px at 14% 20%, rgba(139,0,0,0.18) 0%, transparent 100%),
                radial-gradient(1px 1px at 86% 14%, rgba(139,0,0,0.14) 0%, transparent 100%),
                radial-gradient(1px 1px at 48% 78%, rgba(139,0,0,0.10) 0%, transparent 100%);
            animation: drift 22s ease-in-out infinite alternate;
            pointer-events: none; z-index: 0;
        }
        @keyframes drift {
            0%   { transform: translateY(0) scale(1); opacity: .6; }
            100% { transform: translateY(4px) scale(.99); opacity: .8; }
        }

        /* ── Top Nav ──────────────────────────────────── */
        .top-nav {
            position: sticky; top: 0; z-index: 100;
            display: flex; align-items: center; justify-content: space-between;
            padding: .85rem 2rem;
            background: rgba(5,5,5,.88);
            backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-dark);
            box-shadow: 0 4px 30px rgba(0,0,0,.4);
        }
        .nav-left { display: flex; align-items: center; gap: .8rem; }
        .nav-icon {
            width: 34px; height: 34px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid var(--border-crimson); border-radius: 50%;
            background: var(--crimson-soft);
        }
        .nav-icon svg { width: 16px; height: 16px; fill: var(--crimson); }
        .nav-title { font-family: var(--font-gothic); font-size: .9rem; font-weight: 600; letter-spacing: .06em; }
        .nav-sub { font-size: .72rem; color: var(--text-muted); font-weight: 300; }
        .nav-right { display: flex; align-items: center; gap: .75rem; }
        .nav-user { font-size: .76rem; color: var(--text-secondary); }
        .nav-user strong { color: var(--text-primary); font-weight: 500; }

        /* ── Buttons ──────────────────────────────────── */
        .btn-ghost {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .5rem 1rem;
            font-family: var(--font-gothic); font-size: .68rem; font-weight: 600;
            letter-spacing: .08em; text-transform: uppercase;
            color: var(--text-primary); background: transparent;
            border: 1px solid var(--border-crimson); border-radius: 3px;
            cursor: pointer; text-decoration: none; transition: all .3s;
        }
        .btn-ghost svg { width: 14px; height: 14px; fill: currentColor; }
        .btn-ghost:hover { background: rgba(139,0,0,.1); border-color: var(--crimson); box-shadow: 0 0 16px rgba(139,0,0,.12); }

        .btn-crimson {
            display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
            padding: .75rem 1.4rem;
            font-family: var(--font-gothic); font-size: .76rem; font-weight: 600;
            letter-spacing: .1em; text-transform: uppercase;
            color: var(--text-primary);
            background: linear-gradient(135deg, var(--crimson), #6a0000);
            border: 1px solid rgba(139,0,0,.5); border-radius: 3px;
            cursor: pointer; transition: all .3s;
        }
        .btn-crimson svg { width: 15px; height: 15px; fill: currentColor; }
        .btn-crimson:hover { box-shadow: 0 0 28px rgba(139,0,0,.28); transform: translateY(-1px); }

        /* ── Main wrap ────────────────────────────────── */
        .main-wrap {
            position: relative; z-index: 1;
            max-width: 1100px; width: 100%; margin: 0 auto;
            padding: 2.5rem 2rem 3rem;
        }

        /* ── Card ─────────────────────────────────────── */
        .card {
            position: relative;
            background: var(--bg-card);
            border: 1px solid var(--border-dark); border-radius: 4px;
            box-shadow: 0 0 40px rgba(139,0,0,.05), 0 15px 40px rgba(0,0,0,.35),
                        inset 0 1px 0 rgba(255,255,255,.02);
            animation: cardUp .65s cubic-bezier(.22,1,.36,1) both;
        }
        .card::before {
            content: ''; position: absolute;
            top: 0; left: 50%; transform: translateX(-50%);
            width: 45%; height: 2px;
            background: linear-gradient(90deg, transparent, var(--crimson), transparent);
        }
        .card.d1 { animation-delay: .1s; }
        .card.d2 { animation-delay: .2s; }
        @keyframes cardUp {
            0%   { opacity: 0; transform: translateY(24px) scale(.97); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        .card-header { padding: 1.5rem 1.75rem 0; }
        .card-header h2 {
            font-family: var(--font-gothic); font-size: 1rem; font-weight: 600;
            letter-spacing: .06em; margin-bottom: .25rem;
        }
        .card-header p { font-size: .76rem; color: var(--text-muted); font-weight: 300; line-height: 1.5; }
        .card-body { padding: 1.4rem 1.75rem 1.75rem; }

        /* ── Inputs ───────────────────────────────────── */
        .g-input, .g-textarea {
            width: 100%; padding: .7rem .9rem;
            font-family: var(--font-body); font-size: .85rem;
            color: var(--text-primary);
            background: var(--bg-input);
            border: 1px solid var(--border-dark); border-radius: 3px;
            outline: none; transition: border-color .3s, box-shadow .3s, background .3s;
        }
        .g-input::placeholder, .g-textarea::placeholder { color: var(--text-muted); font-weight: 300; }
        .g-input:focus, .g-textarea:focus {
            border-color: var(--crimson); background: var(--bg-input-focus);
            box-shadow: 0 0 0 3px rgba(139,0,0,.1);
        }
        .g-textarea { resize: vertical; min-height: 64px; font-family: var(--font-body); }

        .form-group { margin-bottom: 1.15rem; }
        .form-group label {
            display: block; font-size: .68rem; font-weight: 500;
            letter-spacing: .1em; text-transform: uppercase;
            color: var(--text-secondary); margin-bottom: .4rem;
        }
        .form-group label .req { color: var(--crimson-light); margin-left: .1rem; }
        .form-hint { font-size: .66rem; color: var(--text-muted); margin-top: .25rem; }

        /* ── Alerts ───────────────────────────────────── */
        .alert {
            margin-bottom: 1.1rem; padding: .75rem 1rem;
            border-radius: 3px; font-size: .8rem; line-height: 1.5;
        }
        .alert-error {
            background: rgba(139,0,0,.08);
            border: 1px solid rgba(139,0,0,.25); border-left: 3px solid var(--crimson);
            color: #d4a0a0;
        }
        .alert-success {
            background: var(--success-soft);
            border: 1px solid rgba(42,122,74,.25); border-left: 3px solid var(--success);
            color: #8cc5a0;
        }

        /* ── Grid helpers ─────────────────────────────── */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem 1rem; }
        .span-full { grid-column: 1 / -1; }

        /* ── Divider ──────────────────────────────────── */
        .section-divider {
            display: flex; align-items: center; gap: .8rem;
            margin: 2.5rem 0 1.5rem; color: var(--text-muted);
            font-size: .65rem; letter-spacing: .1em; text-transform: uppercase;
        }
        .section-divider::before, .section-divider::after {
            content: ''; flex: 1; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-dark), transparent);
        }

        /* ══════════════════════════════════════════════════
           WALLET DASHBOARD (when wallet exists)
           ══════════════════════════════════════════════════ */
        .wallet-dashboard {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;
            margin-bottom: .5rem;
        }
        .stat-tile {
            padding: 1.25rem 1.35rem; border-radius: 4px;
            background: rgba(255,255,255,.015);
            border: 1px solid var(--border-dark);
            transition: border-color .3s, transform .25s;
        }
        .stat-tile:hover {
            border-color: rgba(139,0,0,.2); transform: translateY(-2px);
        }
        .stat-label {
            font-size: .62rem; font-weight: 500; letter-spacing: .1em;
            text-transform: uppercase; color: var(--text-muted); margin-bottom: .4rem;
        }
        .stat-value {
            font-family: var(--font-gothic); font-size: 1.15rem; font-weight: 600;
            color: var(--text-primary); letter-spacing: .03em;
            word-break: break-word;
        }
        .stat-value.crimson { color: var(--crimson-light); }
        .stat-desc {
            margin-top: .75rem; padding-top: .75rem; border-top: 1px solid var(--border-dark);
            font-size: .78rem; color: var(--text-secondary); line-height: 1.6;
            grid-column: 1 / -1;
        }

        /* ══════════════════════════════════════════════════
           WEEKLY LOG SECTION
           ══════════════════════════════════════════════════ */
        .log-split {
            display: grid; grid-template-columns: 360px 1fr;
            gap: 1.75rem; align-items: flex-start;
        }

        /* Data table */
        .log-table-wrap {
            max-height: 440px; overflow-y: auto; padding-right: .2rem;
        }
        .log-table-wrap::-webkit-scrollbar { width: 4px; }
        .log-table-wrap::-webkit-scrollbar-track { background: transparent; }
        .log-table-wrap::-webkit-scrollbar-thumb { background: var(--border-dark); border-radius: 3px; }

        table.log-table {
            width: 100%; border-collapse: collapse;
        }
        .log-table thead th {
            position: sticky; top: 0;
            padding: .65rem .9rem;
            font-size: .62rem; font-weight: 600;
            letter-spacing: .1em; text-transform: uppercase;
            text-align: left; color: var(--text-muted);
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-dark);
        }
        .log-table tbody td {
            padding: .7rem .9rem;
            font-size: .82rem; color: var(--text-secondary);
            border-bottom: 1px solid rgba(26,24,24,.5);
            vertical-align: middle;
        }
        .log-table tbody tr { transition: background .2s; }
        .log-table tbody tr:hover { background: rgba(139,0,0,.03); }

        .week-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 28px; height: 24px; padding: 0 .5rem;
            font-size: .68rem; font-weight: 600;
            color: var(--crimson-light); background: var(--crimson-soft);
            border: 1px solid rgba(139,0,0,.18); border-radius: 3px;
        }
        .count-badge {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 26px; height: 22px; padding: 0 .4rem;
            font-size: .68rem; font-weight: 600;
            color: var(--text-primary); background: rgba(255,255,255,.04);
            border: 1px solid var(--border-dark); border-radius: 3px;
        }

        .empty-table {
            text-align: center; padding: 2.5rem 1rem;
            font-size: .8rem; color: var(--text-muted);
        }

        .feed-count {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 20px; padding: 0 .4rem;
            font-size: .62rem; font-weight: 600;
            color: var(--text-primary); background: var(--crimson-soft);
            border: 1px solid rgba(139,0,0,.18); border-radius: 10px;
            margin-left: .4rem;
        }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 900px) {
            .wallet-dashboard { grid-template-columns: 1fr 1fr; }
            .log-split { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .top-nav { flex-direction: column; gap: .75rem; align-items: flex-start; padding: .75rem 1rem; }
            .nav-right { align-self: flex-end; }
            .main-wrap { padding: 1.5rem 1rem 2rem; }
            .wallet-dashboard { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TOP NAVIGATION                                              -->
<!-- ═══════════════════════════════════════════════════════════ -->
<nav class="top-nav">
    <div class="nav-left">
        <div class="nav-icon">
            <svg viewBox="0 0 24 24"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
        </div>
        <div>
            <span class="nav-title">Favor</span><br>
            <span class="nav-sub">Wallet &amp; Activity Log</span>
        </div>
    </div>
    <div class="nav-right">
        <span class="nav-user">Signed in as <strong><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></strong></span>
        <a href="services.php" class="btn-ghost">
            <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
            Services
        </a>
    </div>
</nav>

<main class="main-wrap">

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- TOP SECTION: FAVORWALLET                                    -->
<!-- ═══════════════════════════════════════════════════════════ -->

<?php if (!$wallet): ?>
<!-- ── No wallet: initialization form ─────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2>Initialize Your Favor Wallet</h2>
        <p>Set up your wallet to start tracking favors and activity within the community.</p>
    </div>
    <div class="card-body">

        <?php if ($wallet_ok): ?>
            <div class="alert alert-success">Wallet created. Reloading…</div>
            <script>setTimeout(()=>location.reload(),800);</script>
        <?php endif; ?>

        <?php if (!empty($wallet_errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($wallet_errors as $e): ?>
                    <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="favor.php" autocomplete="off">
            <input type="hidden" name="action" value="init_wallet">
            <div class="form-row">
                <div class="form-group">
                    <label for="w_name">Wallet Name <span class="req">*</span></label>
                    <input class="g-input" type="text" id="w_name" name="w_name"
                           placeholder="e.g., My Campus Wallet" maxlength="150" required>
                </div>
                <div class="form-group">
                    <label for="w_type">Type <span class="req">*</span></label>
                    <input class="g-input" type="text" id="w_type" name="w_type"
                           placeholder="e.g., Academic, Community, General" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label for="w_number">Starting Balance</label>
                    <input class="g-input" type="number" id="w_number" name="w_number"
                           placeholder="0" value="0" min="0">
                    <div class="form-hint">Initial points or favor balance.</div>
                </div>
                <div class="form-group">
                    <label for="w_desc">Description</label>
                    <textarea class="g-textarea" id="w_desc" name="w_desc" rows="2"
                              placeholder="Optional — describe how you plan to use this wallet."></textarea>
                </div>
            </div>
            <button type="submit" class="btn-crimson" style="width:100%; margin-top:.25rem;">
                <svg viewBox="0 0 24 24"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                Create Wallet
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ── Wallet exists: dashboard ────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <h2>Favor Wallet</h2>
        <p>Your current wallet overview and balance.</p>
    </div>
    <div class="card-body">
        <div class="wallet-dashboard">
            <div class="stat-tile">
                <div class="stat-label">Wallet Name</div>
                <div class="stat-value"><?= htmlspecialchars($wallet['name'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="stat-tile">
                <div class="stat-label">Type</div>
                <div class="stat-value"><?= htmlspecialchars($wallet['type'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="stat-tile">
                <div class="stat-label">Balance / Points</div>
                <div class="stat-value crimson"><?= (int)$wallet['number'] ?></div>
            </div>
            <div class="stat-tile">
                <div class="stat-label">Total Log Entries</div>
                <div class="stat-value"><?= count($logs) ?></div>
            </div>
            <?php if (!empty($wallet['description'])): ?>
                <div class="stat-desc">
                    <strong style="color:var(--text-muted);font-size:.66rem;letter-spacing:.08em;text-transform:uppercase;">Description</strong><br>
                    <?= htmlspecialchars($wallet['description'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- BOTTOM SECTION: WEEKLY ACTIVITY LOG                         -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="section-divider">Weekly Activity Log</div>

<div class="log-split">

    <!-- LEFT: Add Log Entry -->
    <div class="card d1">
        <div class="card-header">
            <h2>Add Log Entry</h2>
            <p>Record a new weekly activity for your favor wallet.</p>
        </div>
        <div class="card-body">

            <?php if ($log_ok): ?>
                <div class="alert alert-success">Activity log entry added successfully.</div>
            <?php endif; ?>

            <?php if (!empty($log_errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($log_errors as $e): ?>
                        <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="favor.php" autocomplete="off">
                <input type="hidden" name="action" value="add_log">
                <input type="hidden" name="favorwallet_id" value="<?= (int)$wallet['id'] ?>">

                <div class="form-group">
                    <label for="weekly_number">Week Number <span class="req">*</span></label>
                    <input class="g-input" type="number" id="weekly_number" name="weekly_number"
                           placeholder="e.g., 1, 2, 3…" min="1" required>
                    <div class="form-hint">The week this activity belongs to.</div>
                </div>

                <div class="form-group">
                    <label for="activity_record">Activity Record <span class="req">*</span></label>
                    <input class="g-input" type="text" id="activity_record" name="activity_record"
                           placeholder="e.g., Helped with assignment review" maxlength="255" required>
                </div>

                <div class="form-group">
                    <label for="activity_count">Activity Count</label>
                    <input class="g-input" type="number" id="activity_count" name="activity_count"
                           placeholder="0" value="0" min="0">
                    <div class="form-hint">Number of times this activity was performed.</div>
                </div>

                <button type="submit" class="btn-crimson" style="width:100%;">
                    <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                    Add Entry
                </button>
            </form>
        </div>
    </div>

    <!-- RIGHT: Log Table -->
    <div class="card d2">
        <div class="card-header">
            <h2>Activity History <span class="feed-count"><?= count($logs) ?></span></h2>
            <p>All recorded weekly activities for your wallet.</p>
        </div>
        <div class="card-body">
            <?php if (empty($logs)): ?>
                <div class="empty-table">
                    No activity has been logged yet. Use the form to record your first entry.
                </div>
            <?php else: ?>
                <div class="log-table-wrap">
                    <table class="log-table">
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Activity</th>
                                <th style="text-align:center;">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><span class="week-badge"><?= (int)$log['weekly_number'] ?></span></td>
                                    <td><?= htmlspecialchars($log['activity_record'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="text-align:center;"><span class="count-badge"><?= (int)$log['activity_count'] ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php endif; ?>

</main>
</body>
</html>
