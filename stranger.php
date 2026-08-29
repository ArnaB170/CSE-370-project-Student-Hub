<?php
// ============================================================
// stranger.php — The Stranger: Anonymous Peer-Connection Hub
// ============================================================
session_start();
require_once 'db_config.php';

// ────────────────────────────────────────────────────────────
// GUARD: Must be authenticated with a completed profile
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

// ────────────────────────────────────────────────────────────
// AJAX API HANDLER — returns JSON, then exits
// ────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];

    // We need the anon_id for all AJAX actions
    $anon_id = null;
    $s = $conn->prepare('SELECT anon_id FROM Anonymous_Profile WHERE profile_id = ?');
    $s->bind_param('i', $profile_id);
    $s->execute();
    $r = $s->get_result();
    if ($r->num_rows > 0) $anon_id = (int)$r->fetch_assoc()['anon_id'];
    $s->close();

    if (!$anon_id) {
        echo json_encode(['error' => 'No anonymous profile']);
        $conn->close();
        exit;
    }

    // ── Send Message ──────────────────────────────────
    if ($action === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input   = json_decode(file_get_contents('php://input'), true);
        $room_id = (int)($input['room_id'] ?? 0);
        $content = trim($input['content'] ?? '');

        if ($room_id > 0 && $content !== '') {
            $s = $conn->prepare('INSERT INTO Room_Message (room_id, anon_id, content) VALUES (?, ?, ?)');
            $s->bind_param('iis', $room_id, $anon_id, $content);
            $s->execute();
            $s->close();
            echo json_encode(['ok' => true, 'message_id' => $conn->insert_id]);
        } else {
            echo json_encode(['error' => 'Invalid input']);
        }
        $conn->close();
        exit;
    }

    // ── Fetch Messages ────────────────────────────────
    if ($action === 'fetch_messages') {
        $room_id  = (int)($_GET['room_id'] ?? 0);
        $after_id = (int)($_GET['after'] ?? 0);

        $s = $conn->prepare(
            'SELECT m.message_id, m.content, m.sent_at, a.pseudonym, a.anon_id
             FROM Room_Message m
             JOIN Anonymous_Profile a ON a.anon_id = m.anon_id
             WHERE m.room_id = ? AND m.message_id > ?
             ORDER BY m.message_id ASC
             LIMIT 100'
        );
        $s->bind_param('ii', $room_id, $after_id);
        $s->execute();
        $res = $s->get_result();
        $messages = [];
        while ($row = $res->fetch_assoc()) {
            $row['is_mine'] = ((int)$row['anon_id'] === $anon_id);
            $messages[] = $row;
        }
        $s->close();
        echo json_encode(['messages' => $messages, 'your_anon_id' => $anon_id]);
        $conn->close();
        exit;
    }

    // ── Fetch Rooms ───────────────────────────────────
    if ($action === 'fetch_rooms') {
        $s = $conn->prepare(
            'SELECT r.room_id, r.room_name, r.created_at, a.pseudonym AS creator,
                    (SELECT COUNT(*) FROM Room_Member rm WHERE rm.room_id = r.room_id) AS member_count
             FROM Stranger_Room r
             JOIN Anonymous_Profile a ON a.anon_id = r.created_by
             WHERE r.is_active = 1
             ORDER BY r.created_at DESC'
        );
        $s->execute();
        $res = $s->get_result();
        $rooms = [];
        while ($row = $res->fetch_assoc()) $rooms[] = $row;
        $s->close();
        echo json_encode(['rooms' => $rooms]);
        $conn->close();
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    $conn->close();
    exit;
}

// ────────────────────────────────────────────────────────────
// CHECK / CREATE Anonymous Profile
// ────────────────────────────────────────────────────────────
$anon_profile = null;
$onboarding_errors = [];

$stmt = $conn->prepare('SELECT anon_id, pseudonym, hobbies FROM Anonymous_Profile WHERE profile_id = ?');
$stmt->bind_param('i', $profile_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $anon_profile = $result->fetch_assoc();
}
$stmt->close();

// Handle anonymous profile creation
if (!$anon_profile && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_anon') {
    $pseudonym = trim($_POST['pseudonym'] ?? '');
    $hobbies   = trim($_POST['hobbies']   ?? '');

    if ($pseudonym === '') {
        $onboarding_errors[] = 'A pseudonym is required to enter.';
    } elseif (strlen($pseudonym) > 100) {
        $onboarding_errors[] = 'Pseudonym must be under 100 characters.';
    }

    if (empty($onboarding_errors)) {
        $s = $conn->prepare('INSERT INTO Anonymous_Profile (profile_id, pseudonym, hobbies) VALUES (?, ?, ?)');
        $s->bind_param('iss', $profile_id, $pseudonym, $hobbies);
        if ($s->execute()) {
            $anon_profile = [
                'anon_id'   => $conn->insert_id,
                'pseudonym' => $pseudonym,
                'hobbies'   => $hobbies,
            ];
        } else {
            $onboarding_errors[] = 'Could not create anonymous profile. Please try again.';
        }
        $s->close();
    }
}

// ────────────────────────────────────────────────────────────
// ROOM ACTIONS: Create / Join / Leave (form POSTs)
// ────────────────────────────────────────────────────────────
if ($anon_profile && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $anon_id = (int)$anon_profile['anon_id'];

    // ── Create Room ───────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'create_room') {
        $room_name = trim($_POST['room_name'] ?? '');
        if ($room_name !== '') {
            $s = $conn->prepare('INSERT INTO Stranger_Room (room_name, created_by) VALUES (?, ?)');
            $s->bind_param('si', $room_name, $anon_id);
            $s->execute();
            $new_room_id = $conn->insert_id;
            $s->close();

            // Auto-join the creator
            $s = $conn->prepare('INSERT INTO Room_Member (room_id, anon_id) VALUES (?, ?)');
            $s->bind_param('ii', $new_room_id, $anon_id);
            $s->execute();
            $s->close();

            $conn->close();
            header('Location: stranger.php?room=' . $new_room_id);
            exit;
        }
    }

    // ── Join Room ─────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'join_room') {
        $room_id = (int)($_POST['room_id'] ?? 0);
        if ($room_id > 0) {
            $s = $conn->prepare('INSERT IGNORE INTO Room_Member (room_id, anon_id) VALUES (?, ?)');
            $s->bind_param('ii', $room_id, $anon_id);
            $s->execute();
            $s->close();

            $conn->close();
            header('Location: stranger.php?room=' . $room_id);
            exit;
        }
    }

    // ── Leave Room ────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'leave_room') {
        $room_id = (int)($_POST['room_id'] ?? 0);
        if ($room_id > 0) {
            $s = $conn->prepare('DELETE FROM Room_Member WHERE room_id = ? AND anon_id = ?');
            $s->bind_param('ii', $room_id, $anon_id);
            $s->execute();
            $s->close();

            $conn->close();
            header('Location: stranger.php');
            exit;
        }
    }
}

// ────────────────────────────────────────────────────────────
// DETERMINE PAGE STATE
// ────────────────────────────────────────────────────────────
$page_state  = 'onboarding'; // default
$current_room = null;

if ($anon_profile) {
    $page_state = 'lobby';
    $anon_id = (int)$anon_profile['anon_id'];

    // If ?room=ID, verify membership and load room data
    if (isset($_GET['room'])) {
        $room_id = (int)$_GET['room'];
        $s = $conn->prepare(
            'SELECT r.room_id, r.room_name, r.created_at,
                    (SELECT COUNT(*) FROM Room_Member rm WHERE rm.room_id = r.room_id) AS member_count
             FROM Stranger_Room r
             JOIN Room_Member rm ON rm.room_id = r.room_id AND rm.anon_id = ?
             WHERE r.room_id = ? AND r.is_active = 1'
        );
        $s->bind_param('ii', $anon_id, $room_id);
        $s->execute();
        $res = $s->get_result();
        if ($res->num_rows > 0) {
            $current_room = $res->fetch_assoc();
            $page_state = 'room';
        }
        $s->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="The Stranger — Anonymous connections behind a veil of anonymity.">
    <title>The Stranger — <?= $page_state === 'room' && $current_room ? htmlspecialchars($current_room['room_name'], ENT_QUOTES, 'UTF-8') : 'Anonymous Hub' ?></title>

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
            --bg-msg-mine:    rgba(139,0,0,0.12);
            --bg-msg-other:   rgba(255,255,255,0.025);
            --crimson:        #8B0000;
            --crimson-light:  #a01020;
            --crimson-soft:   rgba(139,0,0,0.10);
            --text-primary:   #e8e0d8;
            --text-secondary: #8a827a;
            --text-muted:     #5a5550;
            --border-dark:    #1a1818;
            --border-crimson: rgba(139,0,0,0.3);
            --font-gothic:    'Cinzel', 'Garamond', serif;
            --font-body:      'Inter', system-ui, sans-serif;
        }

        html, body { height: 100%; }

        body {
            font-family: var(--font-body);
            color: var(--text-primary);
            background:
                radial-gradient(ellipse 80% 50% at 50% 0%, rgba(139,0,0,0.05) 0%, transparent 60%),
                linear-gradient(180deg, var(--bg-deepest) 0%, var(--bg-dark) 100%);
            background-attachment: fixed;
        }

        /* ── Ambient particles ────────────────────────── */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image:
                radial-gradient(1px 1px at 12% 22%, rgba(139,0,0,0.2) 0%, transparent 100%),
                radial-gradient(1px 1px at 88% 12%, rgba(139,0,0,0.17) 0%, transparent 100%),
                radial-gradient(1px 1px at 55% 82%, rgba(139,0,0,0.12) 0%, transparent 100%),
                radial-gradient(1px 1px at 35% 60%, rgba(200,180,160,0.05) 0%, transparent 100%);
            animation: drift 22s ease-in-out infinite alternate;
            pointer-events: none; z-index: 0;
        }
        @keyframes drift {
            0%   { transform: translateY(0) scale(1); opacity: .65; }
            100% { transform: translateY(4px) scale(.99); opacity: .85; }
        }

        /* ── Shared: Card ─────────────────────────────── */
        .card {
            position: relative; z-index: 1;
            background: var(--bg-card);
            border: 1px solid var(--border-dark);
            border-radius: 4px;
            box-shadow: 0 0 60px rgba(139,0,0,0.06), 0 20px 50px rgba(0,0,0,0.45),
                        inset 0 1px 0 rgba(255,255,255,0.02);
            animation: enterCard .7s cubic-bezier(.22,1,.36,1) both;
        }
        .card::before {
            content: ''; position: absolute;
            top: 0; left: 50%; transform: translateX(-50%);
            width: 50%; height: 2px;
            background: linear-gradient(90deg, transparent, var(--crimson), transparent);
        }
        @keyframes enterCard {
            0%   { opacity: 0; transform: translateY(28px) scale(.97); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Shared: Inputs ───────────────────────────── */
        .g-input {
            width: 100%; padding: .75rem 1rem;
            font-family: var(--font-body); font-size: .88rem;
            color: var(--text-primary);
            background: var(--bg-input);
            border: 1px solid var(--border-dark); border-radius: 3px;
            outline: none;
            transition: border-color .3s, box-shadow .3s, background .3s;
        }
        .g-input::placeholder { color: var(--text-muted); font-weight: 300; }
        .g-input:focus {
            border-color: var(--crimson);
            background: var(--bg-input-focus);
            box-shadow: 0 0 0 3px rgba(139,0,0,.1);
        }
        textarea.g-input { resize: vertical; min-height: 80px; font-family: var(--font-body); }

        /* ── Shared: Buttons ──────────────────────────── */
        .btn-crimson {
            display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
            padding: .75rem 1.4rem;
            font-family: var(--font-gothic); font-size: .78rem; font-weight: 600;
            letter-spacing: .1em; text-transform: uppercase;
            color: var(--text-primary);
            background: linear-gradient(135deg, var(--crimson), #6a0000);
            border: 1px solid rgba(139,0,0,.5); border-radius: 3px;
            cursor: pointer; transition: all .3s; position: relative; overflow: hidden;
        }
        .btn-crimson:hover {
            box-shadow: 0 0 28px rgba(139,0,0,.28); transform: translateY(-1px);
        }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: .4rem;
            padding: .55rem 1rem;
            font-family: var(--font-gothic); font-size: .7rem; font-weight: 600;
            letter-spacing: .08em; text-transform: uppercase;
            color: var(--text-primary); background: transparent;
            border: 1px solid var(--border-crimson); border-radius: 3px;
            cursor: pointer; text-decoration: none; transition: all .3s;
        }
        .btn-ghost:hover {
            background: rgba(139,0,0,.1); border-color: var(--crimson);
            box-shadow: 0 0 16px rgba(139,0,0,.12);
        }
        .btn-ghost svg { width: 15px; height: 15px; fill: currentColor; }

        /* ── Shared: Labels ───────────────────────────── */
        label.g-label {
            display: block; font-size: .7rem; font-weight: 500;
            letter-spacing: .1em; text-transform: uppercase;
            color: var(--text-secondary); margin-bottom: .4rem;
        }
        label.g-label .req { color: var(--crimson-light); margin-left: .1rem; }

        /* ── Shared: Errors ───────────────────────────── */
        .error-box {
            margin-bottom: 1.25rem; padding: .9rem 1.1rem;
            background: rgba(139,0,0,.08);
            border: 1px solid rgba(139,0,0,.25); border-left: 3px solid var(--crimson);
            border-radius: 3px; font-size: .8rem; color: #d4a0a0; line-height: 1.6;
            animation: shake .4s ease-in-out;
        }
        @keyframes shake {
            0%,100%{transform:translateX(0)}20%{transform:translateX(-5px)}40%{transform:translateX(4px)}60%{transform:translateX(-2px)}80%{transform:translateX(1px)}
        }

        /* ── Shared: Divider ──────────────────────────── */
        .divider {
            display: flex; align-items: center; gap: .8rem;
            margin: 1.5rem 0; color: var(--text-muted);
            font-size: .65rem; letter-spacing: .1em; text-transform: uppercase;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-dark), transparent);
        }

        /* ── Emblem ───────────────────────────────────── */
        .emblem {
            width: 60px; height: 60px; margin: 0 auto 1.5rem;
            display: flex; align-items: center; justify-content: center;
            border: 1.5px solid var(--border-crimson); border-radius: 50%;
            background: radial-gradient(circle, rgba(139,0,0,.1), transparent 70%);
            animation: pulseRing 4s ease-in-out infinite;
        }
        .emblem svg { width: 26px; height: 26px; fill: var(--crimson); }
        @keyframes pulseRing {
            0%,100% { box-shadow: 0 0 0 0 rgba(139,0,0,.15); }
            50%     { box-shadow: 0 0 0 10px rgba(139,0,0,0); }
        }

        /* ══════════════════════════════════════════════════
           STATE: ONBOARDING
           ══════════════════════════════════════════════════ */
        .onboard-wrap {
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem;
        }
        .onboard-card {
            width: 100%; max-width: 460px; padding: 2.75rem 2.5rem;
        }
        .onboard-card h1 {
            font-family: var(--font-gothic); font-size: 1.5rem; font-weight: 600;
            letter-spacing: .08em; text-align: center; margin-bottom: .5rem;
        }
        .onboard-sub {
            text-align: center; font-size: .8rem; font-weight: 300;
            color: var(--text-secondary); line-height: 1.65; margin-bottom: 2rem;
        }
        .onboard-sub em { color: var(--text-muted); font-style: italic; font-size: .74rem; }
        .onboard-group { margin-bottom: 1.25rem; }
        .onboard-footer {
            margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-dark);
            text-align: center; font-size: .68rem; color: var(--text-muted); line-height: 1.7;
            font-style: italic;
        }

        /* ══════════════════════════════════════════════════
           STATE: LOBBY
           ══════════════════════════════════════════════════ */
        .lobby-shell {
            min-height: 100vh; display: flex; flex-direction: column;
        }

        /* Nav bar */
        .stranger-nav {
            position: sticky; top: 0; z-index: 100;
            display: flex; align-items: center; justify-content: space-between;
            padding: .85rem 2rem;
            background: rgba(5,5,5,.88); backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-dark);
        }
        .stranger-nav-left { display: flex; align-items: center; gap: .75rem; }
        .stranger-nav-left .nav-icon {
            width: 34px; height: 34px; display: flex; align-items: center; justify-content: center;
            border: 1px solid var(--border-crimson); border-radius: 50%;
            background: var(--crimson-soft);
        }
        .stranger-nav-left .nav-icon svg { width: 16px; height: 16px; fill: var(--crimson); }
        .stranger-nav-left h2 {
            font-family: var(--font-gothic); font-size: .9rem; font-weight: 600;
            letter-spacing: .06em;
        }
        .stranger-nav-left .pseudo-tag {
            font-size: .72rem; color: var(--text-muted); font-weight: 300;
        }
        .stranger-nav-right { display: flex; gap: .75rem; }

        /* Lobby content */
        .lobby-content {
            flex: 1; position: relative; z-index: 1;
            max-width: 960px; width: 100%; margin: 0 auto; padding: 2.5rem 2rem 3rem;
        }

        /* Create room */
        .create-section { margin-bottom: 2.5rem; }
        .create-section h3 {
            font-family: var(--font-gothic); font-size: 1rem; font-weight: 600;
            letter-spacing: .06em; margin-bottom: 1rem;
        }
        .create-form {
            display: flex; gap: .75rem; align-items: flex-start;
        }
        .create-form .g-input { flex: 1; }

        /* Room grid */
        .rooms-section h3 {
            font-family: var(--font-gothic); font-size: 1rem; font-weight: 600;
            letter-spacing: .06em; margin-bottom: 1.25rem;
        }
        .rooms-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.1rem;
        }
        .room-tile {
            padding: 1.4rem 1.5rem; border-radius: 4px;
            background: var(--bg-card); border: 1px solid var(--border-dark);
            transition: border-color .3s, box-shadow .4s, transform .3s;
            cursor: default;
        }
        .room-tile:hover {
            border-color: rgba(139,0,0,.3); transform: translateY(-2px);
            box-shadow: 0 0 30px rgba(139,0,0,.08);
        }
        .room-tile-name {
            font-family: var(--font-gothic); font-size: .95rem; font-weight: 600;
            letter-spacing: .04em; margin-bottom: .5rem;
        }
        .room-tile-meta {
            font-size: .72rem; color: var(--text-muted); margin-bottom: 1rem; line-height: 1.6;
        }
        .room-tile-meta span { color: var(--text-secondary); }

        /* Empty state */
        .empty-state {
            text-align: center; padding: 3rem 1rem;
            animation: enterCard .8s cubic-bezier(.22,1,.36,1) both;
        }
        .empty-state .empty-icon {
            width: 48px; height: 48px; margin: 0 auto 1.25rem;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid var(--border-dark); border-radius: 50%;
            opacity: .5;
        }
        .empty-state .empty-icon svg { width: 22px; height: 22px; fill: var(--text-muted); }
        .empty-state p {
            font-size: .82rem; color: var(--text-muted); line-height: 1.7; max-width: 400px; margin: 0 auto;
        }
        .empty-state .camus {
            display: block; margin-top: .75rem;
            font-style: italic; font-size: .74rem; color: var(--border-crimson);
        }

        /* ══════════════════════════════════════════════════
           STATE: ROOM
           ══════════════════════════════════════════════════ */
        .room-shell {
            height: 100vh; display: flex; flex-direction: column; overflow: hidden;
        }

        /* Room header */
        .room-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: .75rem 1.5rem;
            background: rgba(5,5,5,.9); backdrop-filter: blur(14px);
            border-bottom: 1px solid var(--border-dark); z-index: 10;
        }
        .room-header-left { display: flex; align-items: center; gap: .9rem; }
        .room-header-left h2 {
            font-family: var(--font-gothic); font-size: .95rem; font-weight: 600;
            letter-spacing: .05em;
        }
        .room-badge {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .15rem .55rem; font-size: .62rem; font-weight: 500;
            letter-spacing: .06em; text-transform: uppercase;
            color: var(--crimson-light); background: var(--crimson-soft);
            border: 1px solid rgba(139,0,0,.18); border-radius: 2px;
        }
        .room-badge svg { width: 11px; height: 11px; fill: var(--crimson-light); }
        .room-header-right { display: flex; gap: .6rem; }

        /* Room body: chat + sidebar */
        .room-body {
            flex: 1; display: flex; overflow: hidden;
        }

        /* Chat panel */
        .chat-panel {
            flex: 1; display: flex; flex-direction: column; min-width: 0;
        }
        .chat-messages {
            flex: 1; overflow-y: auto; padding: 1.25rem 1.5rem;
            display: flex; flex-direction: column; gap: .6rem;
        }
        .chat-messages::-webkit-scrollbar { width: 5px; }
        .chat-messages::-webkit-scrollbar-track { background: transparent; }
        .chat-messages::-webkit-scrollbar-thumb { background: var(--border-dark); border-radius: 3px; }

        /* Messages */
        .msg { max-width: 72%; animation: msgIn .3s ease; }
        .msg.mine { align-self: flex-end; }
        .msg.theirs { align-self: flex-start; }
        @keyframes msgIn {
            0% { opacity: 0; transform: translateY(8px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .msg-bubble {
            padding: .65rem 1rem; border-radius: 6px;
            font-size: .84rem; line-height: 1.6; word-break: break-word;
        }
        .msg.mine .msg-bubble {
            background: var(--bg-msg-mine); border: 1px solid rgba(139,0,0,.15);
            border-bottom-right-radius: 2px;
        }
        .msg.theirs .msg-bubble {
            background: var(--bg-msg-other); border: 1px solid var(--border-dark);
            border-bottom-left-radius: 2px;
        }
        .msg-author {
            font-size: .65rem; font-weight: 500; letter-spacing: .06em;
            text-transform: uppercase; margin-bottom: .2rem;
        }
        .msg.mine .msg-author { color: var(--crimson-light); text-align: right; }
        .msg.theirs .msg-author { color: var(--text-muted); }
        .msg-time {
            font-size: .6rem; color: var(--text-muted); margin-top: .2rem;
        }
        .msg.mine .msg-time { text-align: right; }

        /* Chat empty */
        .chat-empty {
            flex: 1; display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            text-align: center; padding: 2rem; opacity: .6;
        }
        .chat-empty svg { width: 36px; height: 36px; fill: var(--text-muted); margin-bottom: 1rem; }
        .chat-empty p { font-size: .8rem; color: var(--text-muted); line-height: 1.7; max-width: 340px; }
        .chat-empty .quote {
            margin-top: .75rem; font-style: italic; font-size: .72rem; color: var(--border-crimson);
        }

        /* Chat input bar */
        .chat-input-bar {
            display: flex; gap: .5rem; padding: .75rem 1.25rem;
            background: rgba(8,8,8,.95); border-top: 1px solid var(--border-dark);
        }
        .chat-input-bar input {
            flex: 1; padding: .7rem 1rem; font-size: .85rem;
            font-family: var(--font-body); color: var(--text-primary);
            background: var(--bg-input); border: 1px solid var(--border-dark);
            border-radius: 3px; outline: none; transition: border-color .3s;
        }
        .chat-input-bar input:focus { border-color: var(--crimson); }
        .chat-input-bar input::placeholder { color: var(--text-muted); font-weight: 300; }
        .chat-input-bar button {
            padding: .7rem 1.1rem; display: flex; align-items: center;
            background: linear-gradient(135deg, var(--crimson), #6a0000);
            border: 1px solid rgba(139,0,0,.5); border-radius: 3px;
            cursor: pointer; transition: box-shadow .3s;
        }
        .chat-input-bar button:hover { box-shadow: 0 0 18px rgba(139,0,0,.25); }
        .chat-input-bar button svg { width: 18px; height: 18px; fill: var(--text-primary); }

        /* ── Right Sidebar (Voice + Screenshare) ──────── */
        .room-sidebar {
            width: 260px; flex-shrink: 0;
            border-left: 1px solid var(--border-dark);
            background: rgba(6,6,6,.7);
            display: flex; flex-direction: column; overflow-y: auto;
        }
        .sidebar-section {
            padding: 1.25rem 1.2rem;
            border-bottom: 1px solid var(--border-dark);
        }
        .sidebar-section:last-child { border-bottom: none; flex: 1; }
        .sidebar-section h4 {
            font-family: var(--font-gothic); font-size: .72rem; font-weight: 600;
            letter-spacing: .1em; text-transform: uppercase; color: var(--text-secondary);
            margin-bottom: 1rem; display: flex; align-items: center; gap: .4rem;
        }
        .sidebar-section h4 svg { width: 15px; height: 15px; fill: var(--crimson); }

        .media-btn {
            width: 100%; padding: .7rem; margin-bottom: .6rem;
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            font-family: var(--font-body); font-size: .76rem; font-weight: 500;
            letter-spacing: .04em; color: var(--text-primary);
            background: var(--bg-input); border: 1px solid var(--border-dark);
            border-radius: 3px; cursor: pointer; transition: all .3s;
        }
        .media-btn svg { width: 16px; height: 16px; fill: currentColor; }
        .media-btn:hover {
            border-color: var(--border-crimson); background: rgba(139,0,0,.06);
        }
        .media-btn.active {
            border-color: var(--crimson); background: var(--crimson-soft);
            color: var(--crimson-light);
        }
        .media-status {
            text-align: center; font-size: .68rem; color: var(--text-muted);
            padding: .5rem 0; font-style: italic;
        }

        .screenshare-preview {
            width: 100%; aspect-ratio: 16/9;
            background: #050505; border: 1px solid var(--border-dark); border-radius: 3px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: .75rem;
        }
        .screenshare-preview svg { width: 28px; height: 28px; fill: var(--text-muted); opacity: .4; }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 768px) {
            .room-body { flex-direction: column; }
            .room-sidebar {
                width: 100%; height: auto; max-height: 200px;
                border-left: none; border-top: 1px solid var(--border-dark);
                flex-direction: row; overflow-x: auto;
            }
            .sidebar-section { min-width: 220px; border-bottom: none; border-right: 1px solid var(--border-dark); }
            .create-form { flex-direction: column; }
            .stranger-nav { padding: .75rem 1rem; }
            .lobby-content { padding: 1.75rem 1rem 2rem; }
        }
        @media (max-width: 480px) {
            .onboard-card { padding: 2rem 1.25rem; }
        }
    </style>
</head>
<body>

<?php // ═════════════════════════════════════════════════════
      //  STATE 1: ANONYMOUS ONBOARDING
      // ═════════════════════════════════════════════════════
if ($page_state === 'onboarding'): ?>

<div class="onboard-wrap">
    <div class="card onboard-card">
        <div class="emblem" aria-hidden="true">
            <!-- Mask icon -->
            <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-2-9c.78 0 1.41-.63 1.41-1.41S10.78 8.18 10 8.18s-1.41.63-1.41 1.41S9.22 11 10 11zm4 0c.78 0 1.41-.63 1.41-1.41S14.78 8.18 14 8.18s-1.41.63-1.41 1.41S13.22 11 14 11zm-2 5.5c2.33 0 4.32-1.45 5.12-3.5H6.88c.8 2.05 2.79 3.5 5.12 3.5z"/></svg>
        </div>

        <h1>Become a Stranger</h1>
        <p class="onboard-sub">
            Leave your identity at the threshold. Within these walls,<br>
            you are no one — and everyone.<br>
            <em>"I opened myself to the gentle indifference of the world."</em>
        </p>

        <?php if (!empty($onboarding_errors)): ?>
            <div class="error-box">
                <?php foreach ($onboarding_errors as $e): ?>
                    <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="stranger.php" autocomplete="off">
            <input type="hidden" name="action" value="create_anon">

            <div class="onboard-group">
                <label class="g-label" for="pseudonym">Pseudonym <span class="req">*</span></label>
                <input class="g-input" type="text" id="pseudonym" name="pseudonym"
                       placeholder="e.g., The Wanderer, Night Owl, Echo" required maxlength="100">
            </div>

            <div class="onboard-group">
                <label class="g-label" for="hobbies">Hobbies &amp; Interests</label>
                <textarea class="g-input" id="hobbies" name="hobbies" rows="3"
                          placeholder="e.g., Reading existential fiction, stargazing, late-night coding…"></textarea>
            </div>

            <button type="submit" class="btn-crimson" style="width:100%; margin-top:.5rem;">
                Step Through the Door
            </button>
        </form>

        <div class="onboard-footer">
            "Every wall is a door." — Ralph Waldo Emerson
        </div>
    </div>
</div>


<?php // ═════════════════════════════════════════════════════
      //  STATE 2: THE LOBBY
      // ═════════════════════════════════════════════════════
elseif ($page_state === 'lobby'): ?>

<div class="lobby-shell">
    <!-- Nav -->
    <nav class="stranger-nav">
        <div class="stranger-nav-left">
            <div class="nav-icon">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-2-9c.78 0 1.41-.63 1.41-1.41S10.78 8.18 10 8.18s-1.41.63-1.41 1.41S9.22 11 10 11zm4 0c.78 0 1.41-.63 1.41-1.41S14.78 8.18 14 8.18s-1.41.63-1.41 1.41S13.22 11 14 11zm-2 5.5c2.33 0 4.32-1.45 5.12-3.5H6.88c.8 2.05 2.79 3.5 5.12 3.5z"/></svg>
            </div>
            <div>
                <h2>The Stranger</h2>
                <span class="pseudo-tag">Signed in as <strong style="color:var(--crimson-light);"><?= htmlspecialchars($anon_profile['pseudonym'], ENT_QUOTES, 'UTF-8') ?></strong></span>
            </div>
        </div>
        <div class="stranger-nav-right">
            <a href="services.php" class="btn-ghost">
                <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                Services
            </a>
        </div>
    </nav>

    <div class="lobby-content">
        <!-- Create Room -->
        <div class="create-section">
            <h3>Summon a Room</h3>
            <form class="create-form" method="POST" action="stranger.php">
                <input type="hidden" name="action" value="create_room">
                <input class="g-input" type="text" name="room_name"
                       placeholder="Name this gathering… e.g., The Midnight Circle"
                       required maxlength="150">
                <button type="submit" class="btn-crimson">Create</button>
            </form>
        </div>

        <div class="divider">Active Rooms</div>

        <!-- Room list (loaded via JS for live updates) -->
        <div class="rooms-section">
            <div id="rooms-container">
                <div class="empty-state" id="rooms-loading">
                    <div class="empty-icon">
                        <svg viewBox="0 0 24 24"><path d="M12 6v3l4-4-4-4v3c-4.42 0-8 3.58-8 8 0 1.57.46 3.03 1.24 4.26L6.7 14.8c-.45-.83-.7-1.79-.7-2.8 0-3.31 2.69-6 6-6zm6.76 1.74L17.3 9.2c.44.84.7 1.79.7 2.8 0 3.31-2.69 6-6 6v-3l-4 4 4 4v-3c4.42 0 8-3.58 8-8 0-1.57-.46-3.03-1.24-4.26z"/></svg>
                    </div>
                    <p>Searching the corridors…</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const container = document.getElementById('rooms-container');

    function fetchRooms() {
        fetch('stranger.php?ajax=fetch_rooms')
            .then(r => r.json())
            .then(data => {
                if (!data.rooms || data.rooms.length === 0) {
                    container.innerHTML =
                        '<div class="empty-state">' +
                            '<div class="empty-icon"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></div>' +
                            '<p>No rooms exist yet. The silence is deafening.</p>' +
                            '<span class="camus">"In the depth of winter, I finally learned that within me there lay an invincible summer." — Albert Camus</span>' +
                        '</div>';
                    return;
                }
                let html = '<div class="rooms-grid">';
                data.rooms.forEach(room => {
                    const time = new Date(room.created_at).toLocaleString(undefined, {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
                    html +=
                        '<div class="room-tile">' +
                            '<div class="room-tile-name">' + escHtml(room.room_name) + '</div>' +
                            '<div class="room-tile-meta">' +
                                'Created by <span>' + escHtml(room.creator) + '</span> · ' + time + '<br>' +
                                '<span>' + room.member_count + '</span> stranger' + (room.member_count != 1 ? 's' : '') + ' inside' +
                            '</div>' +
                            '<form method="POST" action="stranger.php" style="margin:0;">' +
                                '<input type="hidden" name="action" value="join_room">' +
                                '<input type="hidden" name="room_id" value="' + room.room_id + '">' +
                                '<button type="submit" class="btn-ghost" style="width:100%;justify-content:center;">' +
                                    '<svg viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>' +
                                    'Enter Room' +
                                '</button>' +
                            '</form>' +
                        '</div>';
                });
                html += '</div>';
                container.innerHTML = html;
            })
            .catch(() => {
                container.innerHTML =
                    '<div class="empty-state"><p style="color:#d4a0a0;">Failed to load rooms. The corridors remain hidden.</p></div>';
            });
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    fetchRooms();
    setInterval(fetchRooms, 8000);
})();
</script>


<?php // ═════════════════════════════════════════════════════
      //  STATE 3: INSIDE A ROOM
      // ═════════════════════════════════════════════════════
elseif ($page_state === 'room' && $current_room): ?>

<div class="room-shell">

    <!-- Room Header -->
    <header class="room-header">
        <div class="room-header-left">
            <a href="stranger.php" class="btn-ghost" style="padding:.4rem .7rem; font-size:.65rem;">
                <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                Lobby
            </a>
            <h2><?= htmlspecialchars($current_room['room_name'], ENT_QUOTES, 'UTF-8') ?></h2>
            <span class="room-badge">
                <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                <?= (int)$current_room['member_count'] ?>
            </span>
        </div>
        <div class="room-header-right">
            <form method="POST" action="stranger.php" style="margin:0;">
                <input type="hidden" name="action" value="leave_room">
                <input type="hidden" name="room_id" value="<?= (int)$current_room['room_id'] ?>">
                <button type="submit" class="btn-ghost" style="color:#b44;">
                    <svg viewBox="0 0 24 24" style="fill:#b44;"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    Leave
                </button>
            </form>
        </div>
    </header>

    <!-- Room body: Chat + Sidebar -->
    <div class="room-body">

        <!-- Chat Panel -->
        <div class="chat-panel">
            <div class="chat-messages" id="chat-messages">
                <div class="chat-empty" id="chat-empty">
                    <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H5.17L4 17.17V4h16v12z"/></svg>
                    <p>The room holds its breath, waiting for the first word to shatter the silence.</p>
                    <span class="quote">"A cage went in search of a bird." — Franz Kafka</span>
                </div>
            </div>

            <div class="chat-input-bar">
                <input type="text" id="msg-input" placeholder="Write something into the void…" maxlength="2000" autocomplete="off">
                <button type="button" id="btn-send" aria-label="Send message">
                    <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </div>
        </div>

        <!-- Right Sidebar: Voice + Screenshare -->
        <aside class="room-sidebar">

            <!-- Voice Chat Section -->
            <div class="sidebar-section">
                <h4>
                    <svg viewBox="0 0 24 24"><path d="M12 14c1.66 0 2.99-1.34 2.99-3L15 5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"/></svg>
                    Voice Chat
                </h4>
                <button class="media-btn" id="btn-mic" onclick="toggleBtn(this)">
                    <svg viewBox="0 0 24 24"><path d="M12 14c1.66 0 2.99-1.34 2.99-3L15 5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"/></svg>
                    Microphone
                </button>
                <button class="media-btn" id="btn-speaker" onclick="toggleBtn(this)">
                    <svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                    Speaker
                </button>
                <div class="media-status">WebRTC voice not yet connected.</div>
            </div>

            <!-- Screenshare Section -->
            <div class="sidebar-section">
                <h4>
                    <svg viewBox="0 0 24 24"><path d="M20 18c1.1 0 1.99-.9 1.99-2L22 6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zM4 6h16v10H4V6z"/></svg>
                    Screenshare
                </h4>
                <div class="screenshare-preview">
                    <svg viewBox="0 0 24 24"><path d="M21 3H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14zM10 8v8l5-4z"/></svg>
                </div>
                <button class="media-btn" id="btn-screenshare" onclick="toggleBtn(this)">
                    <svg viewBox="0 0 24 24"><path d="M20 18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zm-8-2l-4-4h3V8h2v4h3l-4 4z"/></svg>
                    Start Sharing
                </button>
                <div class="media-status">Screen sharing is not active.</div>
            </div>

        </aside>
    </div>
</div>

<script>
(function() {
    const ROOM_ID  = <?= (int)$current_room['room_id'] ?>;
    const chatBox  = document.getElementById('chat-messages');
    const emptyMsg = document.getElementById('chat-empty');
    const msgInput = document.getElementById('msg-input');
    const btnSend  = document.getElementById('btn-send');
    let lastMsgId  = 0;

    // ── Send message ─────────────────────────────────
    function sendMessage() {
        const text = msgInput.value.trim();
        if (!text) return;
        msgInput.value = '';

        fetch('stranger.php?ajax=send_message', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ room_id: ROOM_ID, content: text })
        })
        .then(r => r.json())
        .then(() => fetchMessages())
        .catch(() => {});
    }

    btnSend.addEventListener('click', sendMessage);
    msgInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });

    // ── Fetch messages ───────────────────────────────
    function fetchMessages() {
        fetch('stranger.php?ajax=fetch_messages&room_id=' + ROOM_ID + '&after=' + lastMsgId)
            .then(r => r.json())
            .then(data => {
                if (!data.messages || data.messages.length === 0) return;

                // Hide empty state
                if (emptyMsg) emptyMsg.style.display = 'none';

                data.messages.forEach(m => {
                    const cls = m.is_mine ? 'mine' : 'theirs';
                    const time = new Date(m.sent_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
                    const div = document.createElement('div');
                    div.className = 'msg ' + cls;
                    div.innerHTML =
                        '<div class="msg-author">' + escHtml(m.pseudonym) + '</div>' +
                        '<div class="msg-bubble">' + escHtml(m.content) + '</div>' +
                        '<div class="msg-time">' + time + '</div>';
                    chatBox.appendChild(div);
                    lastMsgId = Math.max(lastMsgId, parseInt(m.message_id));
                });

                chatBox.scrollTop = chatBox.scrollHeight;
            })
            .catch(() => {});
    }

    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    // Initial load + polling
    fetchMessages();
    setInterval(fetchMessages, 3000);

    // Focus input on load
    msgInput.focus();
})();

// ── Toggle media buttons (UI only) ───────────────
function toggleBtn(btn) {
    btn.classList.toggle('active');
    if (btn.id === 'btn-screenshare') {
        const statusEl = btn.nextElementSibling;
        statusEl.textContent = btn.classList.contains('active')
            ? 'Sharing your screen…'
            : 'Screen sharing is not active.';
        btn.innerHTML = btn.classList.contains('active')
            ? '<svg viewBox="0 0 24 24"><path d="M20 18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zm-8-2l4-4h-3V8h-2v4H8l4 4z"/></svg> Stop Sharing'
            : '<svg viewBox="0 0 24 24"><path d="M20 18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2H0v2h24v-2h-4zm-8-2l-4-4h3V8h2v4h3l-4 4z"/></svg> Start Sharing';
    }
    if (btn.id === 'btn-mic') {
        const sec = btn.parentElement;
        const statusEl = sec.querySelector('.media-status');
        statusEl.textContent = btn.classList.contains('active')
            ? 'Microphone active. WebRTC pending…'
            : 'WebRTC voice not yet connected.';
    }
}
</script>

<?php endif; ?>

</body>
</html>
<?php // Close any remaining connection
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) $conn->close();
?>
