<?php
require_once __DIR__ . '/config.php';

if (BOT_TOKEN === '') {
    die('BOT_TOKEN is empty. Edit config.php first.');
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') 
            ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$url  = "$protocol://$host$dir/bot.php";

$apiUrl = "https://api.telegram.org/bot" . BOT_TOKEN . "/setWebhook?url=" . urlencode($url);

$ctx = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 15,
        'ignore_errors' => true
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false
    ]
]);

$resp = @file_get_contents($apiUrl, false, $ctx);
$data = $resp ? json_decode($resp, true) : null;

?><html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set Webhook</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#212121;color:#e0e0e0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#2b2b2b;border-radius:16px;padding:30px;width:100%;max-width:520px}
h1{font-size:20px;color:#fff;margin-bottom:12px}
pre{background:#1e1e1e;padding:14px;border-radius:10px;font-size:13px;overflow-x:auto;color:#ccc;margin:16px 0;word-break:break-all;white-space:pre-wrap}
.ok{color:#7bff7b}
.fail{color:#ff7b7b}
.btn{display:inline-block;padding:12px 24px;background:#5b9aff;color:#fff;text-decoration:none;border-radius:10px;font-weight:600;margin-top:16px;margin-right:8px}
code{background:#111;padding:2px 6px;border-radius:4px;font-size:12px}
.note{font-size:13px;color:#aaa;margin-top:16px;line-height:1.5}
</style>
</head>
<body>
<div class="card">
<h1>🔗 Webhook Registration</h1>

<p><b>Detected webhook URL:</b></p>
<pre><?=htmlspecialchars($url)?></pre>

<?php if ($resp === false): ?>
<p class="fail">❌ Failed to contact Telegram API from this server.</p>
<p class="note">Your host is blocking outbound requests to api.telegram.org.<br>
Copy and open this URL in your browser instead:</p>
<pre>https://api.telegram.org/bot<?=htmlspecialchars(BOT_TOKEN)?>/setWebhook?url=<?=urlencode($url)?></pre>
<?php elseif ($data && ($data['ok'] ?? false)): ?>
<p class="ok">✅ Webhook registered successfully!</p>
<pre>Status: <?=htmlspecialchars($data['description'] ?? 'OK')?></pre>
<?php else: ?>
<p class="fail">❌ Error: <?=htmlspecialchars($data['description'] ?? 'Unknown')?></p>
<p class="note">Try opening this URL manually in your browser:</p>
<pre>https://api.telegram.org/bot<?=htmlspecialchars(BOT_TOKEN)?>/setWebhook?url=<?=urlencode($url)?></pre>
<?php endif; ?>

<a class="btn" href="https://t.me/zerofriendzerolike_bot" target="_blank">Open Bot</a>
<a class="btn" href="install.php">Back to Install</a>

<div class="note">
After webhook is set, send <code>/start</code> to the bot.<br>
First user becomes admin.
</div>
</div>
</body>
</html>
