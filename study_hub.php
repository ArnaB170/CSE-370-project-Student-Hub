<?php
// ============================================================
// study_hub.php — Study Hub: Room-based Resource Repository
// Mapped from ER: Study_Room → Group → Resource Upload
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

// ── Fetch current user's name ────────────────────────────
$user_name = $_SESSION['user']['name'] ?? 'Student';
$st = $conn->prepare('SELECT name FROM Student WHERE profile_id = ?');
$st->bind_param('i', $profile_id);
$st->execute();
$r = $st->get_result();
if ($r->num_rows > 0) $user_name = $r->fetch_assoc()['name'];
$st->close();

// ────────────────────────────────────────────────────────────
// AJAX API HANDLER
// ────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    // ── Fetch rooms ──────────────────────────────────
    if ($_GET['ajax'] === 'fetch_rooms') {
        $st = $conn->prepare(
            'SELECT sr.room_id, sr.title, sr.created_at, s.name AS creator,
                    sg.group_id, sg.topic, sg.description,
                    (SELECT COUNT(*) FROM Study_Group_Member sgm WHERE sgm.group_id = sg.group_id) AS member_count
             FROM Study_Room sr
             JOIN Student s ON s.profile_id = sr.created_by
             JOIN Study_Group sg ON sg.room_id = sr.room_id
             ORDER BY sr.created_at DESC'
        );
        $st->execute();
        $res = $st->get_result();
        $rooms = [];
        while ($row = $res->fetch_assoc()) $rooms[] = $row;
        $st->close();
        echo json_encode(['rooms' => $rooms]);
        $conn->close();
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    $conn->close();
    exit;
}

// ────────────────────────────────────────────────────────────
// POST ACTIONS: Create Room, Join Room, Leave Room, Upload
// ────────────────────────────────────────────────────────────
$form_errors   = [];
$upload_errors = [];
$upload_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── CREATE STUDY ROOM + GROUP ────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'create_room') {
        $title       = trim($_POST['title']       ?? '');
        $topic       = trim($_POST['topic']       ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($title === '') $form_errors[] = 'Room title is required.';
        if ($topic === '') $form_errors[] = 'Group topic is required.';

        if (empty($form_errors)) {
            $conn->begin_transaction();
            try {
                // Insert Study_Room
                $st = $conn->prepare('INSERT INTO Study_Room (title, created_by) VALUES (?, ?)');
                $st->bind_param('si', $title, $profile_id);
                $st->execute();
                $room_id = $conn->insert_id;
                $st->close();

                // Insert Study_Group (weak entity under room)
                $st = $conn->prepare('INSERT INTO Study_Group (room_id, description, topic) VALUES (?, ?, ?)');
                $st->bind_param('iss', $room_id, $description, $topic);
                $st->execute();
                $group_id = $conn->insert_id;
                $st->close();

                // Auto-join creator (Membership)
                $st = $conn->prepare('INSERT INTO Study_Group_Member (group_id, profile_id) VALUES (?, ?)');
                $st->bind_param('ii', $group_id, $profile_id);
                $st->execute();
                $st->close();

                $conn->commit();
                $conn->close();
                header('Location: study_hub.php?room=' . $room_id);
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $form_errors[] = 'Failed to create the study room. Please try again.';
            }
        }
    }

    // ── JOIN ROOM ────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'join_room') {
        $group_id = (int)($_POST['group_id'] ?? 0);
        $room_id  = (int)($_POST['room_id']  ?? 0);
        if ($group_id > 0) {
            $st = $conn->prepare('INSERT IGNORE INTO Study_Group_Member (group_id, profile_id) VALUES (?, ?)');
            $st->bind_param('ii', $group_id, $profile_id);
            $st->execute();
            $st->close();
            $conn->close();
            header('Location: study_hub.php?room=' . $room_id);
            exit;
        }
    }

    // ── LEAVE ROOM ───────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'leave_room') {
        $group_id = (int)($_POST['group_id'] ?? 0);
        if ($group_id > 0) {
            $st = $conn->prepare('DELETE FROM Study_Group_Member WHERE group_id = ? AND profile_id = ?');
            $st->bind_param('ii', $group_id, $profile_id);
            $st->execute();
            $st->close();
            $conn->close();
            header('Location: study_hub.php');
            exit;
        }
    }

    // ── UPLOAD RESOURCE ──────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'upload_resource') {
        $group_id = (int)($_POST['group_id'] ?? 0);
        $notes    = trim($_POST['notes'] ?? '');
        $links    = trim($_POST['links'] ?? '');

        if ($links === '') $upload_errors[] = 'A resource link is required.';
        if ($group_id <= 0) $upload_errors[] = 'Invalid group.';

        if (empty($upload_errors)) {
            $st = $conn->prepare('INSERT INTO Resource_Upload (group_id, uploaded_by, notes, links) VALUES (?, ?, ?, ?)');
            $st->bind_param('iiss', $group_id, $profile_id, $notes, $links);
            if ($st->execute()) {
                $upload_success = true;
            } else {
                $upload_errors[] = 'Failed to upload resource. Please try again.';
            }
            $st->close();
        }
    }
}

// ────────────────────────────────────────────────────────────
// DETERMINE PAGE STATE
// ────────────────────────────────────────────────────────────
$page_state   = 'lobby';
$current_room = null;
$current_group = null;
$resources    = [];

if (isset($_GET['room'])) {
    $room_id = (int)$_GET['room'];

    // Fetch room + group + verify membership
    $st = $conn->prepare(
        'SELECT sr.room_id, sr.title, sr.created_at, s.name AS creator,
                sg.group_id, sg.topic, sg.description,
                (SELECT COUNT(*) FROM Study_Group_Member sgm WHERE sgm.group_id = sg.group_id) AS member_count
         FROM Study_Room sr
         JOIN Student s ON s.profile_id = sr.created_by
         JOIN Study_Group sg ON sg.room_id = sr.room_id
         JOIN Study_Group_Member sgm ON sgm.group_id = sg.group_id AND sgm.profile_id = ?
         WHERE sr.room_id = ?'
    );
    $st->bind_param('ii', $profile_id, $room_id);
    $st->execute();
    $res = $st->get_result();
    if ($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $current_room  = $row;
        $current_group = $row;
        $page_state    = 'room';
    }
    $st->close();

    // Fetch resources for this group
    if ($current_group) {
        $gid = (int)$current_group['group_id'];
        $st = $conn->prepare(
            'SELECT ru.resource_id, ru.notes, ru.links, ru.uploaded_at, s.name AS uploader
             FROM Resource_Upload ru
             JOIN Student s ON s.profile_id = ru.uploaded_by
             WHERE ru.group_id = ?
             ORDER BY ru.uploaded_at DESC'
        );
        $st->bind_param('i', $gid);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) $resources[] = $row;
        $st->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Study Hub — Create or join study rooms and share academic resources.">
    <title>Study Hub<?= $page_state === 'room' && $current_room ? ' — ' . htmlspecialchars($current_room['title'], ENT_QUOTES, 'UTF-8') : '' ?></title>

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

        html, body { height: 100%; }

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
                radial-gradient(1px 1px at 12% 22%, rgba(139,0,0,0.18) 0%, transparent 100%),
                radial-gradient(1px 1px at 88% 12%, rgba(139,0,0,0.15) 0%, transparent 100%),
                radial-gradient(1px 1px at 50% 80%, rgba(139,0,0,0.10) 0%, transparent 100%);
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
        .nav-title {
            font-family: var(--font-gothic); font-size: .9rem; font-weight: 600;
            letter-spacing: .06em;
        }
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
        .btn-ghost:hover {
            background: rgba(139,0,0,.1); border-color: var(--crimson);
            box-shadow: 0 0 16px rgba(139,0,0,.12);
        }
        .btn-ghost.danger { color: #b44; }
        .btn-ghost.danger svg { fill: #b44; }

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
        .btn-crimson:hover {
            box-shadow: 0 0 28px rgba(139,0,0,.28); transform: translateY(-1px);
        }

        /* ── Inputs ───────────────────────────────────── */
        .g-input, .g-textarea {
            width: 100%; padding: .7rem .9rem;
            font-family: var(--font-body); font-size: .85rem;
            color: var(--text-primary);
            background: var(--bg-input);
            border: 1px solid var(--border-dark); border-radius: 3px;
            outline: none;
            transition: border-color .3s, box-shadow .3s, background .3s;
        }
        .g-input::placeholder, .g-textarea::placeholder { color: var(--text-muted); font-weight: 300; }
        .g-input:focus, .g-textarea:focus {
            border-color: var(--crimson); background: var(--bg-input-focus);
            box-shadow: 0 0 0 3px rgba(139,0,0,.1);
        }
        .g-textarea { resize: vertical; min-height: 70px; font-family: var(--font-body); }

        .form-group { margin-bottom: 1.15rem; }
        .form-group label {
            display: block; font-size: .68rem; font-weight: 500;
            letter-spacing: .1em; text-transform: uppercase;
            color: var(--text-secondary); margin-bottom: .4rem;
        }
        .form-group label .req { color: var(--crimson-light); margin-left: .1rem; }

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

        /* ── Divider ──────────────────────────────────── */
        .divider {
            display: flex; align-items: center; gap: .8rem;
            margin: 1.75rem 0 1.25rem; color: var(--text-muted);
            font-size: .65rem; letter-spacing: .1em; text-transform: uppercase;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-dark), transparent);
        }

        /* ══════════════════════════════════════════════════
           STATE: LOBBY
           ══════════════════════════════════════════════════ */
        .lobby-shell { min-height: 100vh; display: flex; flex-direction: column; }
        .lobby-content {
            flex: 1; position: relative; z-index: 1;
            max-width: 960px; width: 100%; margin: 0 auto; padding: 2.5rem 2rem 3rem;
        }

        /* Create room form */
        .create-section { margin-bottom: 2rem; }
        .create-section h3 {
            font-family: var(--font-gothic); font-size: 1rem; font-weight: 600;
            letter-spacing: .06em; margin-bottom: 1rem;
        }
        .create-card { padding: 1.75rem; }
        .create-fields {
            display: grid; grid-template-columns: 1fr 1fr; gap: .75rem 1rem;
        }
        .create-fields .span-full { grid-column: 1 / -1; }

        /* Room tiles */
        .rooms-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }
        .room-tile {
            padding: 1.4rem 1.5rem; border-radius: 4px;
            background: var(--bg-card); border: 1px solid var(--border-dark);
            transition: border-color .3s, box-shadow .4s, transform .3s;
        }
        .room-tile:hover {
            border-color: rgba(139,0,0,.25); transform: translateY(-2px);
            box-shadow: 0 0 30px rgba(139,0,0,.08);
        }
        .room-tile-title {
            font-family: var(--font-gothic); font-size: .92rem; font-weight: 600;
            letter-spacing: .04em; margin-bottom: .3rem;
        }
        .room-tile-topic {
            display: inline-block; padding: .12rem .5rem;
            font-size: .62rem; font-weight: 500; letter-spacing: .04em;
            color: var(--crimson-light); background: var(--crimson-soft);
            border: 1px solid rgba(139,0,0,.18); border-radius: 2px;
            margin-bottom: .5rem;
        }
        .room-tile-meta {
            font-size: .7rem; color: var(--text-muted); margin-bottom: 1rem; line-height: 1.6;
        }
        .room-tile-meta span { color: var(--text-secondary); }

        /* Empty state */
        .empty-state {
            text-align: center; padding: 3rem 1rem;
        }
        .empty-state .e-icon {
            width: 48px; height: 48px; margin: 0 auto 1rem;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid var(--border-dark); border-radius: 50%; opacity: .5;
        }
        .empty-state .e-icon svg { width: 22px; height: 22px; fill: var(--text-muted); }
        .empty-state p {
            font-size: .82rem; color: var(--text-muted); line-height: 1.7; max-width: 380px; margin: 0 auto;
        }

        /* ══════════════════════════════════════════════════
           STATE: INSIDE ROOM
           ══════════════════════════════════════════════════ */
        .room-shell { min-height: 100vh; display: flex; flex-direction: column; }
        .room-content {
            flex: 1; position: relative; z-index: 1;
            max-width: 1060px; width: 100%; margin: 0 auto; padding: 2rem;
        }

        /* Room info banner */
        .room-banner {
            padding: 1.75rem 2rem; margin-bottom: 2rem;
        }
        .room-banner h2 {
            font-family: var(--font-gothic); font-size: 1.25rem; font-weight: 600;
            letter-spacing: .06em; margin-bottom: .35rem;
        }
        .room-banner-row {
            display: flex; align-items: center; flex-wrap: wrap; gap: .75rem;
            font-size: .75rem; color: var(--text-muted); margin-top: .5rem;
        }
        .badge {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .15rem .6rem; font-size: .62rem; font-weight: 500;
            letter-spacing: .06em; text-transform: uppercase;
            color: var(--crimson-light); background: var(--crimson-soft);
            border: 1px solid rgba(139,0,0,.18); border-radius: 2px;
        }
        .badge svg { width: 11px; height: 11px; fill: var(--crimson-light); }
        .meta-sep { color: var(--border-dark); font-size: .6rem; }
        .room-desc {
            margin-top: .75rem; padding-top: .75rem;
            border-top: 1px solid var(--border-dark);
            font-size: .82rem; color: var(--text-secondary); line-height: 1.65;
        }

        /* Split: Upload + Feed */
        .room-split {
            display: grid; grid-template-columns: 380px 1fr;
            gap: 1.75rem; align-items: flex-start;
        }
        .card.delay { animation-delay: .12s; }

        /* Resource feed */
        .feed-list {
            display: flex; flex-direction: column; gap: .7rem;
            max-height: 520px; overflow-y: auto; padding-right: .25rem;
        }
        .feed-list::-webkit-scrollbar { width: 4px; }
        .feed-list::-webkit-scrollbar-track { background: transparent; }
        .feed-list::-webkit-scrollbar-thumb { background: var(--border-dark); border-radius: 3px; }

        .resource-item {
            padding: 1rem 1.15rem;
            background: rgba(255,255,255,.015);
            border: 1px solid var(--border-dark); border-radius: 4px;
            transition: border-color .3s, transform .25s;
        }
        .resource-item:hover {
            border-color: rgba(139,0,0,.2); transform: translateY(-1px);
        }
        .res-notes {
            font-size: .84rem; line-height: 1.6; margin-bottom: .6rem; word-break: break-word;
        }
        .res-link {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .3rem .65rem;
            font-size: .68rem; font-weight: 500;
            letter-spacing: .05em; text-transform: uppercase;
            color: var(--crimson-light); background: var(--crimson-soft);
            border: 1px solid rgba(139,0,0,.18); border-radius: 3px;
            text-decoration: none; transition: all .2s; word-break: break-all;
        }
        .res-link svg { width: 12px; height: 12px; fill: var(--crimson-light); flex-shrink: 0; }
        .res-link:hover { background: rgba(139,0,0,.18); border-color: var(--crimson); }
        .res-meta {
            margin-top: .5rem;
            font-size: .68rem; color: var(--text-muted);
        }
        .res-meta strong { color: var(--text-secondary); font-weight: 500; }

        .feed-count {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 22px; height: 20px; padding: 0 .4rem;
            font-size: .62rem; font-weight: 600;
            color: var(--text-primary); background: var(--crimson-soft);
            border: 1px solid rgba(139,0,0,.18); border-radius: 10px;
            margin-left: .4rem;
        }

        /* ── Responsive ───────────────────────────────── */
        @media (max-width: 860px) {
            .room-split, .create-fields { grid-template-columns: 1fr; }
            .create-fields .span-full { grid-column: 1; }
        }
        @media (max-width: 600px) {
            .top-nav { flex-direction: column; gap: .75rem; align-items: flex-start; padding: .75rem 1rem; }
            .nav-right { align-self: flex-end; }
            .lobby-content, .room-content { padding: 1.5rem 1rem; }
        }
    </style>
</head>
<body>

<?php // ═════════════════════════════════════════════════════
      //  STATE: LOBBY — Create or Join Study Rooms
      // ═════════════════════════════════════════════════════
if ($page_state === 'lobby'): ?>

<div class="lobby-shell">
    <nav class="top-nav">
        <div class="nav-left">
            <div class="nav-icon">
                <svg viewBox="0 0 24 24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>
            </div>
            <div>
                <span class="nav-title">Study Hub</span><br>
                <span class="nav-sub">Study Rooms &amp; Resources</span>
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

    <div class="lobby-content">

        <!-- ── Create Study Room ──────────────────────── -->
        <div class="create-section">
            <h3>Create a Study Room</h3>

            <?php if (!empty($form_errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($form_errors as $e): ?>
                        <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?><br>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="card create-card">
                <form method="POST" action="study_hub.php" autocomplete="off">
                    <input type="hidden" name="action" value="create_room">
                    <div class="create-fields">
                        <div class="form-group">
                            <label for="title">Room Title <span class="req">*</span></label>
                            <input class="g-input" type="text" id="title" name="title"
                                   placeholder="e.g., Midterm Prep — CSE 221"
                                   maxlength="200" required>
                        </div>
                        <div class="form-group">
                            <label for="topic">Group Topic <span class="req">*</span></label>
                            <input class="g-input" type="text" id="topic" name="topic"
                                   placeholder="e.g., Algorithm Analysis, Network Protocols"
                                   maxlength="200" required>
                        </div>
                        <div class="form-group span-full">
                            <label for="description">Group Description</label>
                            <textarea class="g-textarea" id="description" name="description" rows="2"
                                      placeholder="A brief description of what this study group will cover…"></textarea>
                        </div>
                        <div class="form-group span-full" style="margin-bottom:0;">
                            <button type="submit" class="btn-crimson" style="width:100%;">
                                <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                Create Room &amp; Group
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="divider">Active Study Rooms</div>

        <!-- ── Room List (AJAX) ───────────────────────── -->
        <div id="rooms-container">
            <div class="empty-state">
                <div class="e-icon"><svg viewBox="0 0 24 24"><path d="M12 6v3l4-4-4-4v3c-4.42 0-8 3.58-8 8 0 1.57.46 3.03 1.24 4.26L6.7 14.8c-.45-.83-.7-1.79-.7-2.8 0-3.31 2.69-6 6-6zm6.76 1.74L17.3 9.2c.44.84.7 1.79.7 2.8 0 3.31-2.69 6-6 6v-3l-4 4 4 4v-3c4.42 0 8-3.58 8-8 0-1.57-.46-3.03-1.24-4.26z"/></svg></div>
                <p>Loading study rooms…</p>
            </div>
        </div>

    </div>
</div>

<script>
(function() {
    const box = document.getElementById('rooms-container');

    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function load() {
        fetch('study_hub.php?ajax=fetch_rooms')
            .then(r => r.json())
            .then(data => {
                if (!data.rooms || data.rooms.length === 0) {
                    box.innerHTML =
                        '<div class="empty-state">' +
                            '<div class="e-icon"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></div>' +
                            '<p>No study rooms have been created yet. Be the first to set one up for your course.</p>' +
                        '</div>';
                    return;
                }
                let h = '<div class="rooms-grid">';
                data.rooms.forEach(r => {
                    const t = new Date(r.created_at).toLocaleString(undefined, {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
                    h +=
                        '<div class="room-tile">' +
                            '<div class="room-tile-title">' + esc(r.title) + '</div>' +
                            '<div class="room-tile-topic">' + esc(r.topic) + '</div>' +
                            '<div class="room-tile-meta">' +
                                'Created by <span>' + esc(r.creator) + '</span> · ' + t + '<br>' +
                                '<span>' + r.member_count + '</span> member' + (r.member_count != 1 ? 's' : '') +
                            '</div>' +
                            (r.description ? '<div class="room-tile-meta" style="margin-bottom:.75rem;font-style:italic;">"' + esc(r.description) + '"</div>' : '') +
                            '<form method="POST" action="study_hub.php" style="margin:0;">' +
                                '<input type="hidden" name="action" value="join_room">' +
                                '<input type="hidden" name="group_id" value="' + r.group_id + '">' +
                                '<input type="hidden" name="room_id" value="' + r.room_id + '">' +
                                '<button type="submit" class="btn-ghost" style="width:100%;justify-content:center;">' +
                                    '<svg viewBox="0 0 24 24"><path d="M10 6L8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>' +
                                    'Join Room' +
                                '</button>' +
                            '</form>' +
                        '</div>';
                });
                h += '</div>';
                box.innerHTML = h;
            })
            .catch(() => {
                box.innerHTML = '<div class="empty-state"><p style="color:#d4a0a0;">Failed to load study rooms.</p></div>';
            });
    }

    load();
    setInterval(load, 10000);
})();
</script>


<?php // ═════════════════════════════════════════════════════
      //  STATE: INSIDE A STUDY ROOM
      // ═════════════════════════════════════════════════════
elseif ($page_state === 'room' && $current_room): ?>

<div class="room-shell">
    <nav class="top-nav">
        <div class="nav-left">
            <div class="nav-icon">
                <svg viewBox="0 0 24 24"><path d="M5 13.18v4L12 21l7-3.82v-4L12 17l-7-3.82zM12 3L1 9l11 6 9-4.91V17h2V9L12 3z"/></svg>
            </div>
            <div>
                <span class="nav-title"><?= htmlspecialchars($current_room['title'], ENT_QUOTES, 'UTF-8') ?></span><br>
                <span class="nav-sub">Study Room</span>
            </div>
        </div>
        <div class="nav-right">
            <span class="nav-user"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?></span>
            <a href="study_hub.php" class="btn-ghost">
                <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                Lobby
            </a>
            <form method="POST" action="study_hub.php" style="margin:0;display:inline;">
                <input type="hidden" name="action" value="leave_room">
                <input type="hidden" name="group_id" value="<?= (int)$current_group['group_id'] ?>">
                <button type="submit" class="btn-ghost danger">
                    <svg viewBox="0 0 24 24"><path d="M10.09 15.59L11.5 17l5-5-5-5-1.41 1.41L12.67 11H3v2h9.67l-2.58 2.59zM19 3H5c-1.11 0-2 .9-2 2v4h2V5h14v14H5v-4H3v4c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z"/></svg>
                    Leave
                </button>
            </form>
        </div>
    </nav>

    <div class="room-content">

        <!-- Room Info Banner -->
        <div class="card room-banner">
            <h2><?= htmlspecialchars($current_room['title'], ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="room-banner-row">
                <span class="badge"><?= htmlspecialchars($current_group['topic'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="meta-sep">·</span>
                <span>Created by <strong style="color:var(--text-secondary)"><?= htmlspecialchars($current_room['creator'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                <span class="meta-sep">·</span>
                <span class="badge">
                    <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                    <?= (int)$current_room['member_count'] ?> member<?= (int)$current_room['member_count'] !== 1 ? 's' : '' ?>
                </span>
                <span class="meta-sep">·</span>
                <span>Group #<?= (int)$current_group['group_id'] ?></span>
            </div>
            <?php if (!empty($current_group['description'])): ?>
                <div class="room-desc"><?= htmlspecialchars($current_group['description'], ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>

        <!-- Split: Upload Form + Resource Feed -->
        <div class="room-split">

            <!-- LEFT: Upload Resource -->
            <div class="card">
                <div class="card-header">
                    <h2>Upload Resource</h2>
                    <p>Share notes, links, or materials with this study group.</p>
                </div>
                <div class="card-body">

                    <?php if ($upload_success): ?>
                        <div class="alert alert-success">Resource uploaded successfully.</div>
                    <?php endif; ?>

                    <?php if (!empty($upload_errors)): ?>
                        <div class="alert alert-error">
                            <?php foreach ($upload_errors as $e): ?>
                                <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?><br>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="study_hub.php?room=<?= (int)$current_room['room_id'] ?>" autocomplete="off">
                        <input type="hidden" name="action" value="upload_resource">
                        <input type="hidden" name="group_id" value="<?= (int)$current_group['group_id'] ?>">

                        <div class="form-group">
                            <label for="notes">Notes</label>
                            <textarea class="g-textarea" id="notes" name="notes" rows="3"
                                      placeholder="e.g., Chapter 5 summary covering Dijkstra's algorithm…"></textarea>
                        </div>

                        <div class="form-group">
                            <label for="links">Resource Link <span class="req">*</span></label>
                            <input class="g-input" type="url" id="links" name="links"
                                   placeholder="https://drive.google.com/…" required>
                        </div>

                        <button type="submit" class="btn-crimson" style="width:100%;">
                            <svg viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
                            Upload Resource
                        </button>
                    </form>
                </div>
            </div>

            <!-- RIGHT: Resource Feed -->
            <div class="card delay">
                <div class="card-header">
                    <h2>Shared Resources <span class="feed-count"><?= count($resources) ?></span></h2>
                    <p>Materials uploaded by group members.</p>
                </div>
                <div class="card-body">
                    <?php if (empty($resources)): ?>
                        <div class="empty-state">
                            <div class="e-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg></div>
                            <p>No resources have been shared in this group yet. Use the form to upload the first one.</p>
                        </div>
                    <?php else: ?>
                        <div class="feed-list">
                            <?php foreach ($resources as $res): ?>
                                <div class="resource-item">
                                    <?php if (!empty($res['notes'])): ?>
                                        <div class="res-notes"><?= nl2br(htmlspecialchars($res['notes'], ENT_QUOTES, 'UTF-8')) ?></div>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars($res['links'], ENT_QUOTES, 'UTF-8') ?>"
                                       target="_blank" rel="noopener noreferrer" class="res-link">
                                        <svg viewBox="0 0 24 24"><path d="M19 19H5V5h7V3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg>
                                        Open Link
                                    </a>
                                    <div class="res-meta">
                                        Shared by <strong><?= htmlspecialchars($res['uploader'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        · <?= date('M j, Y · g:i A', strtotime($res['uploaded_at'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<?php endif; ?>

</body>
</html>
