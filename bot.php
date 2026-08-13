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
        'ignore_errors' => true,
        'timeout' => 30
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
            [['text' => '❤️ Generate Real Likes', 'callback_data' => 'generate']],
            [['text' => '🌍 Change Region', 'callback_data' => 'region']],
            [['text' => '📢 Broadcast', 'callback_data' => 'broadcast']]
        ]
    ]);
}

function regionMenu() {
    $regions = ['IND', 'BD', 'BR', 'SG', 'TH', 'VN', 'ME', 'PK', 'US', 'RU'];
    $rows = [];
    $row = [];
    foreach ($regions as $i => $r) {
        $row[] = ['text' => $r, 'callback_data' => 'reg_' . $r];
        if (count($row) === 3) {
            $rows[] = $row;
            $row = [];
        }
    }
    if ($row) $rows[] = $row;
    $rows[] = [['text' => '« Back', 'callback_data' => 'back_main']];
    return json_encode(['inline_keyboard' => $rows]);
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

function getUserRegion($uid) {
    global $db;
    $st = $db->prepare("SELECT data FROM states WHERE user_id = ? AND state = 'pref_region'");
    $st->execute([$uid]);
    $row = $st->fetch();
    if ($row) {
        $d = json_decode($row['data'], true);
        if (!empty($d['region'])) return strtoupper($d['region']);
    }
    return DEFAULT_REGION;
}

function setUserRegion($uid, $region) {
    setState($uid, 'pref_region', json_encode(['region' => strtoupper($region)]));
}

/**
 * Call the configured real like API.
 * Returns: ok, given, before, after, nickname, region, raw, error
 */
function callLikeApi($uid, $region, $amount = 100) {
    $base = rtrim(LIKE_API_URL, '/');
    if (strpos($base, 'YOUR-LIKE-API') !== false) {
        return [
            'ok' => false,
            'error' => 'LIKE_API_URL is still the placeholder. Edit config.php and put a real working endpoint.'
        ];
    }

    $params = [
        'uid'         => $uid,
        'server_name' => $region,
        'region'      => $region,
        'id'          => $uid,
        'amount'      => min((int)$amount, MAX_LIKES_PER_REQUEST),
        'count'       => min((int)$amount, MAX_LIKES_PER_REQUEST),
    ];
    if (LIKE_API_KEY !== '') {
        $params['key'] = LIKE_API_KEY;
        $params['api'] = LIKE_API_KEY;
        $params['coupon'] = LIKE_API_KEY;
    }

    $url = $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($params);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: FreeFireLikesBot/1.0\r\nAccept: application/json\r\n",
            'timeout' => 45,
            'ignore_errors' => true
        ]
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        $ctx2 = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: FreeFireLikesBot/1.0\r\n",
                'content' => http_build_query($params),
                'timeout' => 45,
                'ignore_errors' => true
            ]
        ]);
        $raw = @file_get_contents($base, false, $ctx2);
    }

    if ($raw === false) {
        return ['ok' => false, 'error' => 'API unreachable or timed out.'];
    }

    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'API returned non-JSON: ' . substr($raw, 0, 120)];
    }

    $result = $j['result'] ?? $j;
    $given  = $result['LikesGivenByAPI'] ?? $result['likes_sent'] ?? $result['likes'] ?? $result['Likes'] ?? null;
    $before = $result['LikesbeforeCommand'] ?? $result['LikesBeforeCommand'] ?? $result['before'] ?? null;
    $after  = $result['LikesafterCommand']  ?? $result['LikesAfterCommand']  ?? $result['after']  ?? null;
    $nick   = $result['PlayerNickname'] ?? $result['nickname'] ?? $result['name'] ?? 'Unknown';
    $status = $result['status'] ?? ($j['success'] ? 1 : 0);

    if ($given === null && isset($j['success']) && $j['success']) {
        $given = $j['likes_sent'] ?? 0;
    }

    $ok = ($status == 1) || (!empty($j['success'])) || ($given !== null && (int)$given > 0);

    return [
        'ok'       => (bool)$ok,
        'given'    => (int)($given ?? 0),
        'before'   => $before !== null ? (int)$before : null,
        'after'    => $after  !== null ? (int)$after  : null,
        'nickname' => $nick,
        'region'   => $region,
        'raw'      => $j,
        'error'    => $ok ? null : ($j['message'] ?? $j['error'] ?? 'No likes delivered (maybe daily limit already used)')
    ];
}

function successCard($uid, $region, $apiResult) {
    $req = 'FF-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $ts  = date('Y-m-d H:i:s');
    $nick = htmlspecialchars($apiResult['nickname'] ?? 'Unknown');
    $given = $apiResult['given'] ?? 0;
    $before = $apiResult['before'];
    $after  = $apiResult['after'];

    $lines = "🎉 <b>REAL LIKES SENT</b> 🎉\n\n"
           . "━━━━━━━━━━━━━━━━━━━━\n"
           . "👤 <b>UID:</b>       <code>$uid</code>\n"
           . "🏷 <b>Name:</b>      $nick\n"
           . "🌍 <b>Region:</b>    $region\n"
           . "❤️ <b>Given:</b>     <b>$given</b>\n";

    if ($before !== null) $lines .= "📉 <b>Before:</b>    $before\n";
    if ($after  !== null) $lines .= "📈 <b>After:</b>     $after\n";

    $lines .= "✅ <b>Status:</b>    SUCCESS\n"
            . "🆔 <b>Req ID:</b>    $req\n"
            . "🕐 <b>Time:</b>      $ts\n"
            . "━━━━━━━━━━━━━━━━━━━━\n\n"
            . "Likes are live on the account. Check in-game profile.";

    return $lines;
}

function failCard($uid, $region, $error) {
    return "⚠️ <b>LIKE REQUEST FAILED</b>\n\n"
         . "UID: <code>$uid</code>\n"
         . "Region: $region\n"
         . "Reason: " . htmlspecialchars($error) . "\n\n"
         . "Possible causes:\n"
         . "• Daily limit already reached for this UID\n"
         . "• API key / coupon expired\n"
         . "• Wrong region\n"
         . "• Upstream guest pool empty\n\n"
         . "Try again later or change region.";
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

    $text = trim($m['text'] ?? '');

    if ($text === '/start') {
        clearState($uid);
        $region = getUserRegion($uid);
        $greet = "👋 <b>Welcome to Real Free Fire Likes Bot</b>\n\n"
               . "This bot sends <b>real</b> likes via a live like API.\n"
               . "Current region: <b>$region</b>\n\n"
               . "Tap the button below to start.";
        sendMsg($cid, $greet, mainMenu());
        exit;
    }

    if ($text === '/broadcast' || strpos($text, '/broadcast@') === 0) {
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
            if (!preg_match('/^\d{5,20}$/', $text)) {
                sendMsg($cid, '❌ Invalid UID. Send numbers only (5–20 digits):');
                exit;
            }
            $region = getUserRegion($uid);
            setState($uid, 'awaiting_likes', json_encode(['uid' => $text, 'region' => $region]));
            sendMsg($cid, "👍 UID saved: <code>$text</code>\nRegion: <b>$region</b>\n\n"
                . "Now enter how many likes you want.\n"
                . "• Recommended: <b>100</b> (daily guest limit)\n"
                . "• Enter <code>0</code> for auto 100");
            exit;
        }

        if ($sn === 'awaiting_likes') {
            $likes = (int)$text;
            if ($likes <= 0) $likes = 100;
            if ($likes > MAX_LIKES_PER_REQUEST) $likes = MAX_LIKES_PER_REQUEST;

            $targetUid = $sd['uid'] ?? '';
            $region    = $sd['region'] ?? getUserRegion($uid);

            sendMsg($cid, "⏳ Sending <b>$likes</b> real likes to <code>$targetUid</code> ($region)...\nThis may take 10–40 seconds.");

            $result = callLikeApi($targetUid, $region, $likes);

            if ($result['ok'] && $result['given'] > 0) {
                sendMsg($cid, successCard($targetUid, $region, $result), mainMenu());
            } else {
                $err = $result['error'] ?? 'Unknown error';
                sendMsg($cid, failCard($targetUid, $region, $err), mainMenu());
            }
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
        $region = getUserRegion($uid);
        editMsg($cid, $mid, "📝 Send your Free Fire Player UID (numbers only).\nCurrent region: <b>$region</b>");
        exit;
    }

    if ($data === 'region') {
        editMsg($cid, $mid, '🌍 Choose region:', regionMenu());
        exit;
    }

    if (strpos($data, 'reg_') === 0) {
        $region = substr($data, 4);
        setUserRegion($uid, $region);
        editMsg($cid, $mid, "✅ Region set to <b>$region</b>\n\nReady to generate likes.", mainMenu());
        exit;
    }

    if ($data === 'back_main') {
        editMsg($cid, $mid, 'Main menu:', mainMenu());
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
