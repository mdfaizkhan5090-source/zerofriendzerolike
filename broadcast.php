<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$result = '';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token_check = $_POST['token'] ?? '';
    $message     = $_POST['message'] ?? '';

    if ($token_check !== BOT_TOKEN) {
        $error = 'Invalid bot token.';
    } elseif (trim($message) === '') {
        $error = 'Message cannot be empty.';
    } else {
        $db   = getDB();
        $stmt = $db->query("SELECT user_id FROM users");
        $ids  = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $sent = 0;
        $fail = 0;

        foreach ($ids as $uid) {
            $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/json',
                'content' => json_encode([
                    'chat_id'    => $uid,
                    'text'       => "📢 <b>Broadcast</b>\n\n" . $message,
                    'parse_mode' => 'HTML'
                ]),
                'ignore_errors' => true
            ]]);
            $res = @file_get_contents($url, false, $ctx);
            if ($res && (json_decode($res, true)['ok'] ?? false)) {
                $sent++;
            } else {
                $fail++;
            }
            usleep(200000);
        }
        $result = "✅ Sent to $sent users" . ($fail ? ", $fail failed." : ".");
    }
}
?><html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Broadcast</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#212121;color:#e0e0e0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#2b2b2b;border-radius:16px;padding:30px;width:100%;max-width:460px}
h1{font-size:20px;color:#fff;margin-bottom:6px}
.sub{color:#aaa;font-size:13px;margin-bottom:20px}
label{display:block;font-size:13px;font-weight:600;color:#ccc;margin-bottom:5px;margin-top:14px}
input,textarea{width:100%;padding:12px 14px;border:1px solid #444;border-radius:10px;font-size:15px;background:#1e1e1e;color:#fff;outline:none;font-family:inherit}
input:focus,textarea:focus{border-color:#5b9aff}
textarea{min-height:120px;resize:vertical}
.btn{width:100%;padding:13px;border:none;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;margin-top:18px;background:#5b9aff;color:#fff}
.btn:hover{opacity:.9}
.result{background:#1a3d1f;color:#7bff7b;padding:12px 16px;border-radius:10px;font-size:13px;margin-top:16px}
.error{background:#3d1f1f;color:#ff7b7b;padding:12px 16px;border-radius:10px;font-size:13px;margin-top:16px}
.hint{font-size:12px;color:#888;margin-top:4px}
</style>
</head>
<body>
<div class="card">
<h1>📢 Broadcast</h1>
<p class="sub">Send a message to all bot users</p>
<?php if ($result): ?><div class="result"><?=htmlspecialchars($result)?></div><?php endif; ?>
<?php if ($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
<form method="post">
<label>Bot Token (for authentication)</label>
<input type="password" name="token" placeholder="Enter your bot token" required>
<label>Message</label>
<textarea name="message" placeholder="Type your broadcast message here..." required></textarea>
<div class="hint">HTML formatting supported (&lt;b&gt;, &lt;i&gt;, etc.)</div>
<button class="btn" type="submit">Send Broadcast</button>
</form>
</div>
</body>
</html>
