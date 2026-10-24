<?php
/*
 |---------------------------------------------------------------------------
 | whatsapp.php  (PHP 8+ , MySQLi)
 |---------------------------------------------------------------------------
 | Pehle phpMyAdmin me ye SQL ek baar run karein (usermessage table ke liye):
 |
 |   ALTER TABLE usermessage
 |     ADD COLUMN is_read  TINYINT(1) NOT NULL DEFAULT 0,
 |     ADD COLUMN datetime TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP;
 |
 | Aur usermessage.id AUTO_INCREMENT + PRIMARY KEY honi chahiye.
 |---------------------------------------------------------------------------
 */

session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ---------- Helpers ---------- */

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function db_all(mysqli $c, string $sql, string $types = '', array $params = []): array
{
    $st = $c->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $res  = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    return $rows;
}

function db_one(mysqli $c, string $sql, string $types = '', array $params = []): ?array
{
    $rows = db_all($c, $sql, $types, $params);
    return $rows[0] ?? null;
}

/** INSERT / UPDATE chalata hai, insert_id return karta hai */
function db_run(mysqli $c, string $sql, string $types = '', array $params = []): int
{
    $st = $c->prepare($sql);
    if ($types !== '') $st->bind_param($types, ...$params);
    $st->execute();
    $id = (int) $st->insert_id;
    $st->close();
    return $id;
}

/** +92 300 1234567 / 03001234567 / 923001234567  ->  3001234567 */
function normalize_phone(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) >= 12 && str_starts_with($d, '92')) $d = substr($d, 2);
    elseif (str_starts_with($d, '0')) $d = substr($d, 1);
    return $d;
}

function display_name(array $r): string
{
    $n = trim((string) ($r['name'] ?? ''));
    return $n !== '' ? $n : '+' . $r['c_code'] . ' ' . $r['phone'];
}

/* ---------- Database ---------- */

try {
    $conn = new mysqli("localhost", "root", "", "whatsapp");
    $conn->set_charset("utf8mb4");
} catch (Throwable $e) {
    http_response_code(500);
    die("Database connection failed.");
}

/* ---------- Login check ---------- */

$uuid = (int) ($_SESSION['mwhatsappID'] ?? 0);
if ($uuid <= 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') json_out(['error' => 'Not logged in'], 401);
    header("Location: Pnumber.php");
    exit;
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ---------- AJAX API ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        json_out(['error' => 'Invalid token'], 403);
    }

    try {
        switch ($_POST['action']) {

            /* ----- Number search ----- */
            case 'search':
                $d = normalize_phone((string) ($_POST['search'] ?? ''));
                if (!preg_match('/^\d{10}$/', $d)) json_out(['success' => false, 'data' => []]);

                $rows = db_all($conn,
                    "SELECT id, name, c_code, phone FROM usernumber WHERE phone = ? AND id <> ?",
                    'si', [$d, $uuid]);

                $out = [];
                foreach ($rows as $r) {
                    $out[] = [
                        'id'      => (int) $r['id'],
                        'display' => display_name($r),
                        'phone'   => '+' . $r['c_code'] . ' ' . $r['phone'],
                    ];
                }
                json_out(['success' => count($out) > 0, 'data' => $out]);

            /* ----- Save number (chat list me add) ----- */
            case 'save':
                $pid = (int) ($_POST['pid'] ?? 0);
                if ($pid <= 0 || $pid === $uuid) json_out(['error' => 'Invalid user'], 400);

                $target = db_one($conn, "SELECT id, c_code, phone FROM usernumber WHERE id = ?", 'i', [$pid]);
                if (!$target) json_out(['error' => 'User not found'], 404);

                $exists = db_one($conn,
                    "SELECT id, status FROM person_usersave WHERE user_id = ? AND person_id = ?",
                    'ii', [$uuid, $pid]);

                if ($exists) {
                    if ($exists['status'] !== 'active') {
                        db_run($conn, "UPDATE person_usersave SET status='active' WHERE id = ?", 'i', [(int) $exists['id']]);
                    }
                    json_out(['status' => 'exists', 'pid' => $pid]);
                }

                db_run($conn,
                    "INSERT INTO person_usersave (user_id, person_id, c_code, phone, status) VALUES (?, ?, ?, ?, 'active')",
                    'iiss', [$uuid, $pid, (string) $target['c_code'], (string) $target['phone']]);
                json_out(['status' => 'saved', 'pid' => $pid]);

            /* ----- Sidebar chat list ----- */
            case 'chats':
                $rows = db_all($conn,
                    "SELECT u.id, u.name, u.c_code, u.phone,
                            lm.message   AS last_message,
                            lm.datetime  AS last_time,
                            lm.sender_id AS last_sender,
                            (SELECT COUNT(*) FROM usermessage x
                              WHERE x.sender_id = u.id AND x.receiver_id = ? AND x.is_read = 0) AS unread
                       FROM person_usersave p
                       JOIN usernumber u ON u.id = p.person_id
                  LEFT JOIN usermessage lm ON lm.id = (
                              SELECT MAX(y.id) FROM usermessage y
                               WHERE (y.sender_id = ? AND y.receiver_id = u.id)
                                  OR (y.sender_id = u.id AND y.receiver_id = ?))
                      WHERE p.user_id = ? AND p.status = 'active'
                   ORDER BY (lm.id IS NULL), lm.id DESC, u.name",
                    'iiii', [$uuid, $uuid, $uuid, $uuid]);

                $out = [];
                foreach ($rows as $r) {
                    $out[] = [
                        'id'           => (int) $r['id'],
                        'display'      => display_name($r),
                        'phone'        => '+' . $r['c_code'] . ' ' . $r['phone'],
                        'last_message' => $r['last_message'],
                        'last_time'    => $r['last_time'],
                        'last_mine'    => $r['last_sender'] !== null && (int) $r['last_sender'] === $uuid,
                        'unread'       => (int) $r['unread'],
                    ];
                }
                json_out(['chats' => $out]);

            /* ----- Ek person ke messages (incremental) ----- */
            case 'messages':
                $pid    = (int) ($_POST['pid'] ?? 0);
                $lastId = (int) ($_POST['last_id'] ?? 0);
                if ($pid <= 0) json_out(['error' => 'Invalid user'], 400);

                // samne wale ke messages "read" mark karo
                db_run($conn,
                    "UPDATE usermessage SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0",
                    'ii', [$pid, $uuid]);

                $rows = db_all($conn,
                    "SELECT * FROM (
                        SELECT id, sender_id, message, datetime
                          FROM usermessage
                         WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
                           AND id > ?
                      ORDER BY id DESC LIMIT 200
                     ) t ORDER BY id ASC",
                    'iiiii', [$uuid, $pid, $pid, $uuid, $lastId]);

                $msgs = [];
                foreach ($rows as $r) {
                    $msgs[] = [
                        'id'       => (int) $r['id'],
                        'mine'     => (int) $r['sender_id'] === $uuid,
                        'message'  => $r['message'],
                        'datetime' => $r['datetime'],
                    ];
                }

                $seen = db_one($conn,
                    "SELECT MAX(id) AS m FROM usermessage WHERE sender_id = ? AND receiver_id = ? AND is_read = 1",
                    'ii', [$uuid, $pid]);

                json_out(['messages' => $msgs, 'seen_up_to' => (int) ($seen['m'] ?? 0)]);

            /* ----- Message send ----- */
            case 'send':
                $pid  = (int) ($_POST['pid'] ?? 0);
                $text = trim((string) ($_POST['text'] ?? ''));

                if ($pid <= 0 || $pid === $uuid) json_out(['error' => 'Invalid user'], 400);
                if ($text === '' || mb_strlen($text) > 4000) json_out(['error' => 'Invalid message'], 400);
                if (!db_one($conn, "SELECT id FROM usernumber WHERE id = ?", 'i', [$pid])) {
                    json_out(['error' => 'User not found'], 404);
                }

                // is pair ka chat_id dhoondo, warna naya banao
                $chat = db_one($conn,
                    "SELECT chat_id FROM usermessage
                      WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) LIMIT 1",
                    'iiii', [$uuid, $pid, $pid, $uuid]);
                if ($chat) {
                    $chatId = (int) $chat['chat_id'];
                } else {
                    $n      = db_one($conn, "SELECT COALESCE(MAX(chat_id), 100) + 1 AS n FROM usermessage");
                    $chatId = (int) $n['n'];
                }

                $newId = db_run($conn,
                    "INSERT INTO usermessage (chat_id, sender_id, receiver_id, message) VALUES (?, ?, ?, ?)",
                    'iiis', [$chatId, $uuid, $pid, $text]);

                json_out(['success' => true, 'id' => $newId]);

            default:
                json_out(['error' => 'Unknown action'], 400);
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
        json_out(['error' => 'Server error'], 500);
    }
}

/* ---------- Page data ---------- */

$me     = db_one($conn, "SELECT name, c_code, phone FROM usernumber WHERE id = ?", 'i', [$uuid]);
$myName = $me ? display_name($me) : 'My WhatsApp';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>WhatsApp</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        :root {
            --green: #00a884;
            --dark-green: #008069;
            --light-green: #d9fdd3;
            --bg: #efeae2;
            --white: #ffffff;
            --text: #111b21;
            --muted: #667781;
            --border: #e9edef;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #d9fdd3, #eef5f1);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 25px;
            color: var(--text);
        }

        .app {
            width: 1400px;
            max-width: 100%;
            height: 90vh;
            min-height: 600px;
            display: flex;
            background: var(--white);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.18);
        }

        /* ================= SIDEBAR ================= */

        .sidebar {
            width: 370px;
            background: #fff;
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            height: 70px;
            padding: 0 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f0f2f5;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .profile span {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .avatar {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: linear-gradient(135deg, #00a884, #128c7e);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            box-shadow: 0 4px 10px rgba(0, 168, 132, 0.25);
            flex-shrink: 0;
        }

        .header-icons {
            display: flex;
            gap: 20px;
            color: #54656f;
            font-size: 21px;
        }

        .icon {
            cursor: pointer;
            transition: 0.2s;
        }

        .icon:hover {
            color: var(--green);
            transform: translateY(-2px);
        }

        /* Search */

        .search-area {
            padding: 10px 12px;
            background: #fff;
            position: relative;
            width: 100%;
        }

        .search-box {
            height: 42px;
            background: #f0f2f5;
            border-radius: 9px;
            display: flex;
            align-items: center;
            padding: 0 15px;
            gap: 10px;
            color: var(--muted);
        }

        .search-box input {
            border: none;
            outline: none;
            background: transparent;
            width: 100%;
            font-size: 14px;
        }

        .search-results {
            display: none;
            position: absolute;
            top: calc(100% - 4px);
            left: 12px;
            right: 12px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 8px 0;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            z-index: 100;
        }

        .search-result {
            padding: 10px 15px;
            cursor: pointer;
        }

        .search-result:hover {
            background: #f5f5f5;
        }

        .search-result small {
            display: block;
            color: var(--muted);
            font-size: 12px;
        }

        .search-result.info {
            cursor: default;
            color: var(--muted);
            font-size: 13px;
        }

        .search-result.info:hover {
            background: transparent;
        }

        /* Chat List */

        .chat-list {
            overflow-y: auto;
            flex: 1;
        }

        .empty-list {
            padding: 30px 20px;
            text-align: center;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.5;
        }

        .chat {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 12px 15px;
            cursor: pointer;
            border-bottom: 1px solid #f0f2f5;
            transition: 0.2s;
        }

        .chat:hover {
            background: #f5f6f6;
        }

        .chat.active {
            background: #e9edef;
        }

        .chat-info {
            flex: 1;
            min-width: 0;
        }

        .chat-top {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 5px;
        }

        .chat-name {
            font-weight: 600;
            font-size: 15px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .time {
            color: var(--muted);
            font-size: 12px;
            flex-shrink: 0;
        }

        .chat-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .message-preview {
            color: var(--muted);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .message-notification {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            padding: 0 7px;
            background: linear-gradient(135deg, #ff416c, #6e1100);
            color: #fff;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 700;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
        }

        /* ================= CHAT AREA ================= */

        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            background: var(--bg);
        }

        .empty-state {
            display: none;
            flex: 1;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--muted);
            text-align: center;
            padding: 20px;
        }

        .empty-state h2 {
            color: var(--text);
            font-weight: 600;
            font-size: 22px;
        }

        .chat-area.no-chat>.chat-header,
        .chat-area.no-chat>.messages,
        .chat-area.no-chat>.message-input {
            display: none;
        }

        .chat-area.no-chat>.empty-state {
            display: flex;
        }

        .chat-header {
            height: 70px;
            background: #f0f2f5;
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 13px;
            border-bottom: 1px solid #dfe3e5;
        }

        .back-btn {
            display: none;
            font-size: 22px;
            color: #54656f;
        }

        .chat-header-info {
            flex: 1;
            min-width: 0;
        }

        .chat-header-info h3 {
            font-size: 16px;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-header-info p {
            font-size: 12px;
            color: var(--muted);
        }

        /* Messages */

        .messages {
            flex: 1;
            overflow-y: auto;
            padding: 30px 8%;
            display: flex;
            flex-direction: column;
            gap: 8px;
            background:
                radial-gradient(circle at 20% 20%, rgba(255, 255, 255, .5), transparent 25%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, .45), transparent 25%),
                var(--bg);
        }

        .date {
            align-self: center;
            background: #fff;
            color: var(--muted);
            font-size: 11px;
            padding: 7px 14px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
            margin: 6px 0 10px;
        }

        .message {
            max-width: 65%;
            padding: 9px 12px 7px;
            border-radius: 9px;
            position: relative;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .12);
            font-size: 14px;
            line-height: 1.45;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }

        .message-text {
            white-space: pre-wrap;
        }

        .received {
            align-self: flex-start;
            background: #fff;
            border-top-left-radius: 2px;
        }

        .sent {
            align-self: flex-end;
            background: var(--light-green);
            border-top-right-radius: 2px;
        }

        .message-time {
            display: inline-block;
            color: #667781;
            font-size: 10px;
            margin-left: 10px;
            float: right;
            margin-top: 5px;
        }

        .checks {
            color: #8696a0;
            font-size: 13px;
        }

        .checks.seen {
            color: #53bdeb;
        }

        /* ================= INPUT ================= */

        .message-input {
            min-height: 68px;
            background: #f0f2f5;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
        }

        .input-icons {
            display: flex;
            gap: 15px;
            color: #54656f;
            font-size: 22px;
        }

        .input-box {
            flex: 1;
            background: #fff;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            padding: 0 15px;
        }

        .input-box input {
            border: none;
            outline: none;
            width: 100%;
            font-size: 14px;
        }

        .send-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            border: none;
            background: var(--green);
            color: white;
            cursor: pointer;
            font-size: 18px;
            box-shadow: 0 5px 15px rgba(0, 168, 132, .25);
            transition: 0.25s;
        }

        .send-btn:hover {
            background: var(--dark-green);
            transform: scale(1.08);
        }

        .send-btn:disabled {
            opacity: .6;
            cursor: default;
            transform: none;
        }

        /* ================= SCROLLBAR ================= */

        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-thumb {
            background: #c4c9cc;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        /* ================= RESPONSIVE ================= */

        @media (max-width: 900px) {
            body {
                padding: 0;
            }

            .app {
                width: 100%;
                height: 100vh;
                min-height: 100vh;
                border-radius: 0;
            }

            .sidebar {
                width: 320px;
            }

            .messages {
                padding-left: 5%;
                padding-right: 5%;
            }

            .message {
                max-width: 75%;
            }
        }

        @media (max-width: 700px) {
            .sidebar {
                width: 100%;
            }

            .chat-area {
                display: none;
            }

            .app.chat-open .sidebar {
                display: none;
            }

            .app.chat-open .chat-area {
                display: flex;
            }

            .back-btn {
                display: block;
            }

            .sidebar-header {
                height: 64px;
            }

            .chat {
                padding: 13px 15px;
            }

            .avatar {
                width: 48px;
                height: 48px;
            }
        }

        @media (max-width: 480px) {
            .header-icons {
                gap: 14px;
                font-size: 19px;
            }

            .profile span {
                font-size: 14px;
            }

            .messages {
                padding: 20px 12px;
            }

            .message {
                max-width: 85%;
                font-size: 13px;
            }

            .message-input {
                padding: 8px 10px;
                gap: 7px;
            }

            .input-icons {
                gap: 8px;
                font-size: 19px;
            }

            .send-btn {
                width: 42px;
                height: 42px;
            }
        }
    </style>
</head>

<body>

    <div class="app" id="app">

        <!-- SIDEBAR -->
        <aside class="sidebar">

            <div class="sidebar-header">
                <div class="profile">
                    <div class="avatar" id="myAvatar">A</div>
                    <span id="myName"><?= htmlspecialchars($myName) ?></span>
                </div>

                <div class="header-icons">
                    <span class="icon">◉</span>
                    <span class="icon">⋮</span>
                </div>
            </div>

            <div class="search-area">
                <div class="search-box">
                    <span>⌕</span>
                    <input type="search" id="searchInput" placeholder="Search by phone number" autocomplete="off">
                </div>
                <div class="search-results" id="searchResults"></div>
            </div>

            <div class="chat-list" id="chatList">
                <div class="empty-list">Loading chats…</div>
            </div>

        </aside>


        <!-- CHAT -->
        <main class="chat-area no-chat" id="chatArea">

            <div class="empty-state">
                <h2>My WhatsApp</h2>
                <p>Select a chat, or search a phone number to start a new one.</p>
            </div>

            <header class="chat-header">
                <span class="icon back-btn" id="backBtn">←</span>
                <div class="avatar" id="chatAvatar">A</div>

                <div class="chat-header-info">
                    <h3 id="chatName"></h3>
                    <p id="chatPhone"></p>
                </div>

                <div class="header-icons">
                    <span class="icon">⌕</span>
                    <span class="icon">⋮</span>
                </div>
            </header>

            <section class="messages" id="messages"></section>

            <footer class="message-input">
                <div class="input-icons">
                    <span class="icon">☺</span>
                    <span class="icon">＋</span>
                </div>

                <div class="input-box">
                    <input type="text" id="messageInput" placeholder="Type a message" maxlength="4000" autocomplete="off" />
                </div>

                <button class="send-btn" id="sendMessagebtn">➤</button>
            </footer>
        </main>
    </div>


<script src="https://code.jquery.com/jquery-4.0.0.js" integrity="sha256-9fsHeVnKBvqh3FB2HYu7g2xseAZ5MlN6Kz/qnkASV8U=" crossorigin="anonymous"></script>
<script>
$(function () {

    const API  = "whatsapp.php";
    const CSRF = <?= json_encode($csrf) ?>;

    let chats      = [];
    let currentPid = null;
    let lastMsgId  = 0;
    let lastDay    = null;
    let searchSeq  = 0;
    let searchTimer = null;

    /* ---------- helpers ---------- */

    function api(action, data) {
        return $.ajax({
            url: API,
            method: "POST",
            dataType: "json",
            data: Object.assign({ action: action, csrf: CSRF }, data || {})
        });
    }

    function esc(s) {
        return $("<div>").text(s == null ? "" : String(s)).html();
    }

    function initials(name) {
        const m = String(name).trim().split(/\s+/)
            .filter(function (w) { return /^\p{L}/u.test(w); })
            .slice(0, 2)
            .map(function (w) { return Array.from(w)[0].toUpperCase(); })
            .join("");
        return m || "#";
    }

    function parseDate(dt) {
        return new Date(String(dt).replace(" ", "T"));
    }

    function fmtTime(dt) {
        return parseDate(dt).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
    }

    function dayDiff(d) {
        const a = new Date(); a.setHours(0, 0, 0, 0);
        const b = new Date(d); b.setHours(0, 0, 0, 0);
        return Math.round((a - b) / 86400000);
    }

    function dayLabel(d) {
        const diff = dayDiff(d);
        if (diff === 0) return "TODAY";
        if (diff === 1) return "YESTERDAY";
        return d.toLocaleDateString([], { day: "numeric", month: "short", year: "numeric" });
    }

    function listTime(dt) {
        if (!dt) return "";
        const d = parseDate(dt);
        const diff = dayDiff(d);
        if (diff === 0) return fmtTime(dt);
        if (diff === 1) return "Yesterday";
        return d.toLocaleDateString([], { day: "numeric", month: "short" });
    }

    function normalizePhone(raw) {
        let d = String(raw).replace(/\D+/g, "");
        if (d.length >= 12 && d.indexOf("92") === 0) d = d.slice(2);
        else if (d.indexOf("0") === 0) d = d.slice(1);
        return d;
    }

    /* ---------- my avatar ---------- */

    $("#myAvatar").text(initials($("#myName").text()));

    /* ---------- chat list ---------- */

    function renderChats(list) {
        chats = list;

        if (!list.length) {
            $("#chatList").html('<div class="empty-list">No chats yet.<br>Search a phone number above to add someone.</div>');
            return;
        }

        const html = list.map(function (c) {
            const active  = c.id === currentPid;
            const unread  = active ? 0 : c.unread;
            const preview = c.last_message
                ? (c.last_mine ? "You: " : "") + c.last_message
                : "Tap to start chatting";

            return '<div class="chat' + (active ? " active" : "") + '" data-pid="' + c.id + '">' +
                '<div class="avatar">' + esc(initials(c.display)) + '</div>' +
                '<div class="chat-info">' +
                    '<div class="chat-top">' +
                        '<span class="chat-name">' + esc(c.display) + '</span>' +
                        '<span class="time">' + esc(listTime(c.last_time)) + '</span>' +
                    '</div>' +
                    '<div class="chat-bottom">' +
                        '<div class="message-preview">' + esc(preview) + '</div>' +
                        (unread > 0 ? '<div class="message-notification">' + unread + '</div>' : '') +
                    '</div>' +
                '</div>' +
            '</div>';
        }).join("");

        $("#chatList").html(html);
    }

    function refreshChats() {
        return api("chats").done(function (res) {
            renderChats(res.chats || []);
        });
    }

    /* ---------- open a chat ---------- */

    function openChat(pid) {
        const chat = chats.find(function (c) { return c.id === pid; });
        if (!chat) return;

        currentPid = pid;
        lastMsgId  = 0;
        lastDay    = null;

        $("#messages").empty();
        $("#chatName").text(chat.display);
        $("#chatPhone").text(chat.phone);
        $("#chatAvatar").text(initials(chat.display));
        $("#chatArea").removeClass("no-chat");
        $("#app").addClass("chat-open");

        $(".chat").removeClass("active").filter('[data-pid="' + pid + '"]').addClass("active");
        $(".chat.active .message-notification").remove();

        loadMessages(true);
        $("#messageInput").trigger("focus");
    }

    $(document).on("click", ".chat", function () {
        openChat(parseInt($(this).attr("data-pid"), 10));
    });

    $("#backBtn").on("click", function () {
        $("#app").removeClass("chat-open");
        $("#chatArea").addClass("no-chat");
        currentPid = null;
        refreshChats();
    });

    /* ---------- messages ---------- */

    function appendMessages(msgs) {
        const box = $("#messages");

        msgs.forEach(function (m) {
            if (m.id > lastMsgId) lastMsgId = m.id;
            if ($("#msg-" + m.id).length) return;

            const d   = parseDate(m.datetime);
            const day = d.toDateString();
            if (day !== lastDay) {
                box.append('<div class="date">' + esc(dayLabel(d)) + '</div>');
                lastDay = day;
            }

            const ticks = m.mine ? ' <span class="checks">✓</span>' : "";
            box.append(
                '<div class="message ' + (m.mine ? "sent" : "received") + '" id="msg-' + m.id + '" data-id="' + m.id + '">' +
                    '<span class="message-text">' + esc(m.message) + '</span>' +
                    '<span class="message-time">' + esc(fmtTime(m.datetime)) + ticks + '</span>' +
                '</div>'
            );
        });
    }

    function markSeen(upTo) {
        if (!upTo) return;
        $("#messages .message.sent").each(function () {
            if (parseInt($(this).attr("data-id"), 10) <= upTo) {
                $(this).find(".checks").addClass("seen").text("✓✓");
            }
        });
    }

    function loadMessages(force) {
        const pid = currentPid;
        if (!pid) return $.Deferred().resolve().promise();

        return api("messages", { pid: pid, last_id: lastMsgId }).done(function (res) {
            if (pid !== currentPid) return;   // beech me chat badal gayi

            const el = $("#messages")[0];
            const nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120;

            appendMessages(res.messages || []);
            markSeen(res.seen_up_to);

            if (force || nearBottom) el.scrollTop = el.scrollHeight;
        });
    }

    /* ---------- send ---------- */

    function sendMessage() {
        const text = $("#messageInput").val().trim();
        if (!text || !currentPid) return;

        const btn = $("#sendMessagebtn").prop("disabled", true);

        api("send", { pid: currentPid, text: text })
            .done(function () {
                $("#messageInput").val("");
                loadMessages(true);
                refreshChats();
            })
            .fail(function (xhr) {
                console.log(xhr.responseText);
                alert("Message could not be sent. Please try again.");
            })
            .always(function () {
                btn.prop("disabled", false);
                $("#messageInput").trigger("focus");
            });
    }

    $("#sendMessagebtn").on("click", function (e) {
        e.preventDefault();
        sendMessage();
    });

    $("#messageInput").on("keydown", function (e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    /* ---------- search ---------- */

    function hideSearch() {
        $("#searchResults").hide().empty();
    }

    $("#searchInput").on("input", function () {
        const raw = $(this).val().trim();
        clearTimeout(searchTimer);

        if (raw === "") { searchSeq++; hideSearch(); return; }

        if (normalizePhone(raw).length < 10) {
            searchSeq++;
            $("#searchResults")
                .html('<div class="search-result info">Enter the full 10-digit number (e.g. 3001234567)</div>')
                .show();
            return;
        }

        searchTimer = setTimeout(function () {
            const seq = ++searchSeq;

            api("search", { search: raw })
                .done(function (res) {
                    if (seq !== searchSeq) return;   // purana response ignore

                    let html = "";
                    if (res.success) {
                        res.data.forEach(function (row) {
                            html += '<div class="search-result" data-numid="' + row.id + '">' +
                                esc(row.display) + '<small>' + esc(row.phone) + '</small></div>';
                        });
                    } else {
                        html = '<div class="search-result info">No user found</div>';
                    }
                    $("#searchResults").html(html).show();
                })
                .fail(function (xhr) {
                    console.log(xhr.responseText);
                    $("#searchResults").html('<div class="search-result info">Search failed. Try again.</div>').show();
                });
        }, 300);
    });

    // search result par click -> number save + chat open
    $(document).on("click", ".search-result[data-numid]", function () {
        const pid = parseInt($(this).attr("data-numid"), 10);

        api("save", { pid: pid })
            .done(function () {
                $("#searchInput").val("");
                hideSearch();
                refreshChats().done(function () { openChat(pid); });
            })
            .fail(function (xhr) {
                console.log(xhr.responseText);
                alert("Could not add this number. Please try again.");
            });
    });

    $(document).on("click", function (e) {
        if (!$(e.target).closest(".search-area").length) hideSearch();
    });

    /* ---------- start + polling ---------- */

    refreshChats();

    setInterval(function () {
        refreshChats();
        if (currentPid) loadMessages(false);
    }, 3000);

});
</script>

</body>

</html>
