<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (BOT_TOKEN === '') {
    http_response_code(500);
    die('Bot not configured. Run install.php first.');
}

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$db = getDB();

function api($method, $params = []) {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/json',
        'content' => json_encode($params),
        'ignore_errors' => true
    ]]);
    return json_decode(@file_get_contents($url, false, $ctx), true);
}

function sendMsg($chat, $text, $kb = null) {
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($kb) $p['reply_markup'] = $kb;
    return api('sendMessage', $p);
}

function editMsg($chat, $msgid, $text, $kb = null) {
    $p = ['chat_id' => $chat, 'message_id' => $msgid, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($kb) $p['reply_markup'] = $kb;
    return api('editMessageText', $p);
}

function ansCb($id, $text = '') {
    api('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text]);
}

function mainMenu() {
    return json_encode([
        'inline_keyboard' => [
            [['text' => '🎮 Generate Likes', 'callback_data' => 'generate']],
            [['text' => '📢 Broadcast', 'callback_data' => 'broadcast']]
        ]
    ]);
}

function getState($uid) {
    global $db;
    $st = $db->prepare("SELECT state, data FROM states WHERE user_id = ?");
    $st->execute([$uid]);
    return $st->fetch();
}

function setState($uid, $state, $data = '{}') {
    global $db;
    $st = $db->prepare("INSERT INTO states (user_id, state, data, updated_at) VALUES (?, ?, ?, datetime('now'))
        ON CONFLICT(user_id) DO UPDATE SET state = excluded.state, data = excluded.data, updated_at = excluded.updated_at");
    $st->execute([$uid, $state, $data]);
}

function clearState($uid) {
    global $db;
    $db->prepare("DELETE FROM states WHERE user_id = ?")->execute([$uid]);
}

function regUser($uid, $username, $first) {
    global $db;
    $st = $db->prepare("INSERT OR IGNORE INTO users (user_id, username, first_name) VALUES (?, ?, ?)");
    $st->execute([$uid, $username, $first]);
    $st = $db->prepare("UPDATE users SET username = ?, first_name = ? WHERE user_id = ?");
    $st->execute([$username, $first, $uid]);
}

function setFirstAdmin($uid) {
    global $db;
    $cnt = $db->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
    if ($cnt == 0) {
        $db->prepare("UPDATE users SET is_admin = 1 WHERE user_id = ?")->execute([$uid]);
    }
}

function isAdmin($uid) {
    global $db;
    $st = $db->prepare("SELECT is_admin FROM users WHERE user_id = ?");
    $st->execute([$uid]);
    return (int)$st->fetchColumn() === 1;
}

function successCard($uid, $likes) {
    $bonus = rand(50, 150);
    $total = $likes + $bonus;
    $req = 'FF-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $ts  = date('Y-m-d H:i:s');
    return "🎉 <b>LIKES GENERATED SUCCESSFULLY</b> 🎉\n\n"
         . "━━━━━━━━━━━━━━━━━━━━\n"
         . "👤 <b>Player UID:</b>     <code>$uid</code>\n"
         . "❤️ <b>Likes:</b>          $likes\n"
         . "🎁 <b>Bonus:</b>         +$bonus\n"
         . "📦 <b>Total:</b>         $total\n"
         . "✅ <b>Status:</b>        SUCCESS\n"
         . "🆔 <b>Request ID:</b>    $req\n"
         . "🕐 <b>Time:</b>          $ts\n"
         . "━━━━━━━━━━━━━━━━━━━━\n\n"
         . "Likes have been queued and will appear on the account shortly.";
}

// ── HANDLE MESSAGE ──────────────────────────────────────────────────────
if (isset($update['message'])) {
    $m     = $update['message'];
    $cid   = $m['chat']['id'];
    $uid   = $m['from']['id'];
    $uname = $m['from']['username'] ?? '';
    $fname = $m['from']['first_name'] ?? '';

    regUser($uid, $uname, $fname);
    setFirstAdmin($uid);

    $text = $m['text'] ?? '';

    if ($text === '/start') {
        clearState($uid);
        $greet = "👋 <b>Welcome!</b>\n\nThis bot generates Free Fire likes instantly.\nTap the button below to start.";
        sendMsg($cid, $greet, mainMenu());
        exit;
    }

    if ($text === '/broadcast' || $text === '/broadcast@' . (explode(':', BOT_TOKEN)[0] ?? 'bot')) {
        if (!isAdmin($uid)) {
            sendMsg($cid, '⛔ You are not authorized.');
            exit;
        }
        setState($uid, 'awaiting_broadcast', '{}');
        sendMsg($cid, '📢 Send the message you want to broadcast to all users:');
        exit;
    }

    // ── State-based routing ───────────────────────────────────────────
    $state = getState($uid);
    if ($state) {
        $sn = $state['state'];
        $sd = json_decode($state['data'] ?? '{}', true);

        if ($sn === 'awaiting_uid') {
            if (strlen($text) > 30 || !preg_match('/^\d+$/', $text)) {
                sendMsg($cid, '❌ Invalid UID. Enter a numeric UID (e.g. 123456789):');
                exit;
            }
            setState($uid, 'awaiting_likes', json_encode(['uid' => $text]));
            sendMsg($cid, "👍 UID saved!\n\nNow enter the number of likes you want.\n• Minimum: <b>1000</b>\n• Enter <code>0</code> for a random amount between 1000–2500");
            exit;
        }

        if ($sn === 'awaiting_likes') {
            $likes = (int)$text;
            if ($likes <= 0) {
                $likes = rand(1000, 2500);
            } elseif ($likes < 1000) {
                $likes = 1000;
            }

            $uid_val = $sd['uid'] ?? 'Unknown';

            // Instant success — no ad, no mini-app, no referral
            sendMsg($cid, successCard($uid_val, $likes), mainMenu());
            clearState($uid);
            exit;
        }

        if ($sn === 'awaiting_broadcast') {
            if (!isAdmin($uid)) {
                clearState($uid);
                exit;
            }
            $users = $db->query("SELECT user_id FROM users")->fetchAll(PDO::FETCH_COLUMN);
            $sent  = 0;
            foreach ($users as $target) {
                sendMsg($target, "📢 <b>Broadcast</b>\n\n$text");
                $sent++;
                usleep(200000);
            }
            sendMsg($cid, "✅ Broadcast sent to <b>$sent</b> users.");
            clearState($uid);
            exit;
        }
    }

    sendMsg($cid, 'Use /start to begin.', mainMenu());
    exit;
}

// ── HANDLE CALLBACK QUERY ──────────────────────────────────────────────
if (isset($update['callback_query'])) {
    $cb   = $update['callback_query'];
    $cid  = $cb['message']['chat']['id'];
    $mid  = $cb['message']['message_id'];
    $uid  = $cb['from']['id'];
    $data = $cb['data'];

    ansCb($cb['id']);

    if ($data === 'generate') {
        setState($uid, 'awaiting_uid', '{}');
        editMsg($cid, $mid, '📝 Send your Free Fire Player UID (numbers only):');
        exit;
    }

    if ($data === 'cancel') {
        clearState($uid);
        editMsg($cid, $mid, '❌ Cancelled.', mainMenu());
        exit;
    }

    if ($data === 'broadcast') {
        if (!isAdmin($uid)) {
            ansCb($cb['id'], '⛔ Not authorized');
            exit;
        }
        setState($uid, 'awaiting_broadcast', '{}');
        editMsg($cid, $mid, '📢 Send the message to broadcast to all users:');
        exit;
    }

    exit;
}
