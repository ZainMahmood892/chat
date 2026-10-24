<?php
session_start();

$conn = mysqli_connect("localhost", "root", "", "whatsapp");
if (!$conn) {
    die("Database connection failed");
}
mysqli_set_charset($conn, "utf8mb4");

// ---------- Login check ----------
$uuid = (int) ($_SESSION['mwhatsappID'] ?? 0);
if ($uuid <= 0) {
    header("Location: login.php");
    exit;
}

// ---------- Helper functions ----------
function json_out(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$token = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['token'] ?? '') : '';

/* =====================================================
   TOKEN 123 : Search user by phone number
   ===================================================== */
if ($token === '123') {
    $search = str_replace('+92', '', trim($_POST['search'] ?? ''));
    $search = preg_replace('/\s+/', '', $search);

    if (!preg_match('/^[0-9]{10}$/', $search)) {
        json_out(['success' => false, 'data' => []]);
    }

    // Password wagera kabhi nahi bhejna, sirf id aur phone
    $stmt = $conn->prepare("SELECT id, phone FROM usernumber WHERE phone = ?");
    $stmt->bind_param("s", $search);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    json_out(['success' => count($data) > 0, 'data' => $data]);
}

/* =====================================================
   TOKEN 111 : Save number (add contact)
   ===================================================== */
if ($token === '111') {
    $personId = (int) ($_POST['savenumID'] ?? 0);

    $stmt = $conn->prepare("SELECT phone FROM usernumber WHERE id = ?");
    $stmt->bind_param("i", $personId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        json_out(['save' => 'user not found']);
    }

    $stmt = $conn->prepare("SELECT id FROM person_usersave WHERE user_id = ? AND person_id = ?");
    $stmt->bind_param("ii", $uuid, $personId);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    if ($exists) {
        json_out(['save' => 'number already saved']);
    }

    $code   = '92';
    $status = 'active';
    $stmt = $conn->prepare(
        "INSERT INTO person_usersave (user_id, person_id, c_code, phone, status) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("iisss", $uuid, $personId, $code, $user['phone'], $status);
    $ok = $stmt->execute();
    $stmt->close();

    json_out(['save' => $ok ? 'number saved' : 'number not saved']);
}

/* =====================================================
   TOKEN 333 : Load messages of one chat
   ===================================================== */
if ($token === '333') {
    $Spid = (int) ($_POST['SaveUserid'] ?? 0);

    // Contact sirf wahi jo logged-in user ka apna ho
    $stmt = $conn->prepare("SELECT person_id FROM person_usersave WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $Spid, $uuid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_out(['status' => false, 'html' => '<div class="no-message">User not found</div>']);
    }

    $personId = (int) $row['person_id'];

    // Dusre bande ke unread messages ab read
    $stmt = $conn->prepare(
        "UPDATE usermessage SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0"
    );
    $stmt->bind_param("ii", $personId, $uuid);
    $stmt->execute();
    $stmt->close();

    // Messages
    $stmt = $conn->prepare(
        "SELECT sender_id, message, datetime FROM usermessage
         WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
         ORDER BY datetime ASC, id ASC"
    );
    $stmt->bind_param("iiii", $uuid, $personId, $personId, $uuid);
    $stmt->execute();
    $result = $stmt->get_result();

    $html = '';
    if ($result->num_rows === 0) {
        $html = '<div class="no-message">No messages found</div>';
    } else {
        while ($m = $result->fetch_assoc()) {
            $text = e($m['message']);
            $ts   = strtotime($m['datetime'] ?? '');
            $time = $ts ? date('h:i A', $ts) : '';

            if ((int) $m['sender_id'] === $uuid) {
                $html .= '<div class="message sent">' . $text .
                         '<span class="message-time">' . $time . ' <span class="checks">✓✓</span></span></div>';
            } else {
                $html .= '<div class="message received">' . $text .
                         '<span class="message-time">' . $time . '</span></div>';
            }
        }
    }
    $stmt->close();

    json_out(['status' => true, 'html' => $html]);
}

/* =====================================================
   TOKEN 222 : Send message
   ===================================================== */
if ($token === '222') {
    $userMessage = trim((string) ($_POST['Usermessage'] ?? ''));
    $recID       = (int) ($_POST['id'] ?? 0);

    if ($userMessage === '' || $recID <= 0) {
        json_out(['status' => false]);
    }
    $userMessage = mb_substr($userMessage, 0, 2000);

    // Contact sirf wahi jo logged-in user ka apna ho
    $stmt = $conn->prepare("SELECT person_id FROM person_usersave WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $recID, $uuid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        json_out(['status' => false]);
    }

    $spid = (int) $row['person_id'];

    // chat_id hamesha same rahe (A->B aur B->A dono ke liye)
    $cid = min($uuid, $spid) . '0' . max($uuid, $spid);

    // Apne aap ko bheja hua message read hota hai, badge nahi banega
    $isRead = ($spid === $uuid) ? 1 : 0;

    $stmt = $conn->prepare(
        "INSERT INTO usermessage (chat_id, sender_id, receiver_id, message, is_read) VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("siisi", $cid, $uuid, $spid, $userMessage, $isRead);
    $ok = $stmt->execute();
    $stmt->close();

    // Receiver ki list mein sender na ho to add kar do, warna usay badge dikhega hi nahi
    if ($ok && $spid !== $uuid) {
        $stmt = $conn->prepare("SELECT id FROM person_usersave WHERE user_id = ? AND person_id = ?");
        $stmt->bind_param("ii", $spid, $uuid);
        $stmt->execute();
        $stmt->store_result();
        $hasContact = $stmt->num_rows > 0;
        $stmt->close();

        if (!$hasContact) {
            $stmt = $conn->prepare("SELECT phone FROM usernumber WHERE id = ?");
            $stmt->bind_param("i", $uuid);
            $stmt->execute();
            $me = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($me) {
                $code   = '92';
                $status = 'active';
                $stmt = $conn->prepare(
                    "INSERT INTO person_usersave (user_id, person_id, c_code, phone, status) VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("iisss", $spid, $uuid, $code, $me['phone'], $status);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    json_out(['status' => $ok]);
}

/* =====================================================
   Sidebar contacts (page load)
   ===================================================== */
$stmt = $conn->prepare("
    SELECT p.id, p.person_id, p.c_code, p.phone,
        (SELECT m.message FROM usermessage m
          WHERE (m.sender_id = p.user_id AND m.receiver_id = p.person_id)
             OR (m.sender_id = p.person_id AND m.receiver_id = p.user_id)
          ORDER BY m.datetime DESC, m.id DESC LIMIT 1) AS last_message,
        (SELECT m.datetime FROM usermessage m
          WHERE (m.sender_id = p.user_id AND m.receiver_id = p.person_id)
             OR (m.sender_id = p.person_id AND m.receiver_id = p.user_id)
          ORDER BY m.datetime DESC, m.id DESC LIMIT 1) AS last_time,
        (SELECT m.sender_id FROM usermessage m
          WHERE (m.sender_id = p.user_id AND m.receiver_id = p.person_id)
             OR (m.sender_id = p.person_id AND m.receiver_id = p.user_id)
          ORDER BY m.datetime DESC, m.id DESC LIMIT 1) AS last_sender,
        (SELECT COUNT(*) FROM usermessage m
          WHERE m.sender_id = p.person_id
            AND m.receiver_id = p.user_id
            AND p.person_id <> p.user_id
            AND m.is_read = 0) AS unread
    FROM person_usersave p
    WHERE p.user_id = ?
    ORDER BY (last_time IS NULL), last_time DESC, p.id
");

if (!$stmt) {
    die("SQL Error: " . $conn->error);
}

$stmt->bind_param("i", $uuid);
$stmt->execute();
$contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function contactDisplayName(array $c, int $uuid): string
{
    if ((int) $c['person_id'] === $uuid) {
        return 'You';
    }
    return '+' . $c['c_code'] . ' ' . $c['phone'];
}

function contactAvatar(array $c, int $uuid): string
{
    if ((int) $c['person_id'] === $uuid) {
        return 'ME';
    }
    return substr($c['phone'], -2);
}

function chatTime(?string $dt): string
{
    if (!$dt || !($ts = strtotime($dt))) {
        return '';
    }
    if (date('Y-m-d', $ts) === date('Y-m-d')) {
        return date('h:i A', $ts);
    }
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    return date('d/m/Y', $ts);
}

$first       = $contacts[0] ?? null;
$firstName   = $first ? contactDisplayName($first, $uuid) : 'Select a chat';
$firstAvatar = $first ? contactAvatar($first, $uuid) : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>WhatsApp UI Design</title>

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
            --shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
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

        .profile span {
            font-weight: 600;
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
            top: calc(100% + 5px);
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

        /* Chat List */

        .chat-list {
            overflow-y: auto;
            flex: 1;
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
            margin-bottom: 5px;
        }

        .chat-name {
            font-weight: 600;
            font-size: 15px;
        }

        .time {
            color: var(--muted);
            font-size: 12px;
        }

        .message-preview {
            color: var(--muted);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .online {
            color: var(--green);
            font-size: 12px;
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
            font-family: Arial, sans-serif;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
            transition: all 0.2s ease;
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

        .chat-header {
            height: 70px;
            background: #f0f2f5;
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 13px;
            border-bottom: 1px solid #dfe3e5;
        }

        .chat-header-info {
            flex: 1;
        }

        .chat-header-info h3 {
            font-size: 16px;
            margin-bottom: 3px;
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

        .no-message {
            align-self: center;
            background: #fff;
            color: var(--muted);
            font-size: 12px;
            padding: 7px 14px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
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
            color: #53bdeb;
            font-size: 13px;
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
            opacity: .5;
            cursor: not-allowed;
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
    <div class="app">

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="profile">
                    <div class="avatar">A</div>
                    <span>My WhatsApp</span>
                </div>
                <div class="header-icons">
                    <span class="icon">◉</span>
                    <span class="icon">⋮</span>
                </div>
            </div>

            <div class="search-area">
                <div class="search-box">
                    <span>⌕</span>
                    <input type="search" id="searchInput" placeholder="Search or start new chat">
                </div>
                <div class="search-results" id="searchResults"></div>
            </div>

            <div class="chat-list">
<?php if ($contacts): foreach ($contacts as $i => $c):

    $displayName = contactDisplayName($c, $uuid);
    $avatar      = contactAvatar($c, $uuid);
    $time        = chatTime($c['last_time']);

    if ($c['last_message'] !== null) {
        $preview = ((int) $c['last_sender'] === $uuid ? 'You: ' : '') . $c['last_message'];
    } else {
        $preview = 'No messages yet';
    }

    $unread = (int) $c['unread'];
?>
                <div class="chat personSelect <?= $i === 0 ? 'active' : '' ?>"
                     data-id="<?= (int) $c['id'] ?>"
                     data-name="<?= e($displayName) ?>"
                     data-avatar="<?= e($avatar) ?>">
                    <div class="avatar"><?= e($avatar) ?></div>
                    <div class="chat-info">
                        <div class="chat-top">
                            <span class="chat-name"><?= e($displayName) ?></span>
                            <span class="time"><?= e($time) ?></span>
                        </div>
                        <div style="display:flex; justify-content:space-between; gap:8px;">
                            <div class="message-preview"><?= e($preview) ?></div>
                            <?php if ($unread > 0): ?>
                                <div class="message-notification"><?= $unread > 99 ? '99+' : $unread ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
<?php endforeach; else: ?>
                <p style="padding:15px;color:#999;">No contacts yet</p>
<?php endif; ?>
            </div>
        </aside>

        <!-- CHAT -->
        <main class="chat-area">
            <header class="chat-header">
                <div class="avatar" id="Uimg"><?= e($firstAvatar) ?></div>
                <div class="chat-header-info">
                    <h3 id="Unumber"><?= e($firstName) ?></h3>
                    <p class="online">online</p>
                </div>
                <div class="header-icons">
                    <span class="icon">⌕</span>
                    <span class="icon">⋮</span>
                </div>
            </header>

            <!-- MESSAGES -->
            <section class="messages" id="messages"></section>

            <!-- MESSAGE INPUT -->
            <footer class="message-input">
                <div class="input-icons">
                    <span class="icon">☺</span>
                    <span class="icon">＋</span>
                </div>

                <div class="input-box">
                    <input type="text" id="messageInput" placeholder="Type a message" autocomplete="off" />
                </div>

                <button class="send-btn" id="sendMessagebtn" disabled>➤</button>
            </footer>
        </main>
    </div>

<script src="https://code.jquery.com/jquery-4.0.0.js" integrity="sha256-9fsHeVnKBvqh3FB2HYu7g2xseAZ5MlN6Kz/qnkASV8U=" crossorigin="anonymous"></script>
<script>
$(function () {

    let currentChatId = 0;
    const $input   = $('#messageInput');
    const $sendBtn = $('#sendMessagebtn');

    // Send button tabhi enable jab text ho
    $input.on('input', function () {
        $sendBtn.prop('disabled', $input.val().trim() === '');
    });

    // Enter dabane se bhi send
    $input.on('keydown', function (e) {
        if (e.key === 'Enter' && !$sendBtn.prop('disabled')) {
            e.preventDefault();
            $sendBtn.trigger('click');
        }
    });

    // ---------- Messages load ----------
    function messageLoad(id) {
        $.ajax({
            url: 'whatsapp.php', type: 'POST', dataType: 'json',
            data: { SaveUserid: id, token: '333' },
            success: function (res) {
                $('#messages').html(res.html);   // section ke andar daalo, replace nahi
                const box = document.getElementById('messages');
                if (box) box.scrollTop = box.scrollHeight;
            },
            error: function (xhr) { console.log(xhr.responseText); }
        });
    }

    // ---------- Chat open ----------
    function openChat($chat) {
        $('.personSelect').removeClass('active');
        $chat.addClass('active');
        $chat.find('.message-notification').remove();   // badge hata do

        currentChatId = $chat.attr('data-id');
        $('#Unumber').text($chat.attr('data-name'));
        $('#Uimg').text($chat.attr('data-avatar'));

        messageLoad(currentChatId);
    }

    $(document).on('click', '.personSelect', function () {
        openChat($(this));
    });

    // Page khulte hi pehli chat khul jaye
    const $first = $('.personSelect.active').first();
    if ($first.length) openChat($first);

    // ---------- Send ----------
    $sendBtn.on('click', function (e) {
        e.preventDefault();
        if (!currentChatId) { alert('Pehle chat select karo'); return; }

        const text = $input.val().trim();
        if (text === '') return;

        $sendBtn.prop('disabled', true);

        $.ajax({
            url: 'whatsapp.php', type: 'POST', dataType: 'json',
            data: { Usermessage: text, id: currentChatId, token: '222' },
            success: function (res) {
                if (res.status) {
                    $input.val('');
                    messageLoad(currentChatId);
                } else {
                    $sendBtn.prop('disabled', false);
                    alert('Message send nahi hua');
                }
            },
            error: function (xhr) {
                console.log(xhr.responseText);
                $sendBtn.prop('disabled', false);
                alert('Internal server error');
            }
        });
    });

    // ---------- Search ----------
    $('#searchInput').on('input', function () {
        const search = $(this).val().trim();
        if (search === '') { $('#searchResults').empty().hide(); return; }

        $.ajax({
            url: 'whatsapp.php', type: 'POST', dataType: 'json',
            data: { search: search, token: '123' },
            success: function (response) {
                let html = '';
                if (response.success) {
                    response.data.forEach(function (row) {
                        html += '<div class="search-result person-result" data-num-id="' + row.id + '">+92 ' + row.phone + '</div>';
                    });
                } else {
                    html = '<div class="search-result">No user found</div>';
                }
                $('#searchResults').html(html).show();
            },
            error: function (xhr) { console.log(xhr.responseText); }
        });
    });

    // ---------- Search result click: number save ----------
    $(document).on('click', '.person-result', function () {
        $.ajax({
            url: 'whatsapp.php', type: 'POST', dataType: 'json',
            data: { savenumID: $(this).data('num-id'), token: '111' },
            success: function (res) {
                if (res.save === 'number saved') {
                    window.location.reload();
                } else {
                    alert(res.save);
                }
            },
            error: function (xhr) { console.log(xhr.responseText); alert('Internal Server Error'); }
        });
    });

    // Search box ke bahar click karne par results band
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.search-area').length) {
            $('#searchResults').hide();
        }
    });
});
</script>

</body>
</html>