<?php
$step  = $_POST['step'] ?? 'form';
$token = trim($_POST['token'] ?? '');
$error = '';
$success = '';
$botname = '';

function api($method, $token, $params = []) {
    $url = "https://api.telegram.org/bot$token/$method";
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($params),
            'timeout' => 15,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['ok' => false, 'description' => 'Cannot reach Telegram API from this server (host may block outbound requests)'];
    }
    return json_decode($raw, true) ?: ['ok' => false, 'description' => 'Invalid JSON from Telegram'];
}

if ($step === 'install' && $token !== '') {
    $me = api('getMe', $token);
    if (!$me || !($me['ok'] ?? false)) {
        $desc = $me['description'] ?? 'Unknown error';
        $error = 'Invalid bot token or server cannot reach Telegram. (' . htmlspecialchars($desc) . ')';
        // Still allow writing config so user can finish manually
        if (strpos($desc, 'Cannot reach') !== false) {
            $config  = "<?php\n";
            $config .= "define('BOT_TOKEN', '" . str_replace("'", "\\'", $token) . "');\n";
            $config .= "define('DB_PATH', __DIR__ . '/database.db');\n";
            @file_put_contents(__DIR__ . '/config.php', $config);
            $error .= '<br><br><b>Config was still written.</b> Continue with manual steps below.';
        }
    } else {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') 
                    ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir  = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $base = "$protocol://$host$dir";
        $webhook_url = "$base/bot.php";

        $config  = "<?php\n";
        $config .= "define('BOT_TOKEN', '" . str_replace("'", "\\'", $token) . "');\n";
        $config .= "define('DB_PATH', __DIR__ . '/database.db');\n";

        if (@file_put_contents(__DIR__ . '/config.php', $config) === false) {
            $error = 'Cannot write config.php. Check file permissions (chmod 666 or make folder writable).';
        } else {
            // Create database
            try {
                $db = new PDO("sqlite:" . __DIR__ . "/database.db");
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $db->exec("CREATE TABLE IF NOT EXISTS users (
                    user_id INTEGER PRIMARY KEY,
                    username TEXT DEFAULT '',
                    first_name TEXT DEFAULT '',
                    is_admin INTEGER DEFAULT 0,
                    created_at TEXT DEFAULT (datetime('now'))
                )");
                $db->exec("CREATE TABLE IF NOT EXISTS states (
                    user_id INTEGER PRIMARY KEY,
                    state TEXT NOT NULL,
                    data TEXT DEFAULT '{}',
                    updated_at TEXT DEFAULT (datetime('now'))
                )");
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }

            if (!$error) {
                $wh = api('setWebhook', $token, ['url' => $webhook_url]);
                if (!$wh || !($wh['ok'] ?? false)) {
                    $desc = $wh['description'] ?? 'unknown error';
                    $error = 'Config + DB created, but webhook failed: ' . htmlspecialchars($desc);
                    $error .= '<br><br><b>Manual webhook URL:</b><br><code>' . htmlspecialchars($webhook_url) . '</code>';
                    $error .= '<br><br>Open this in browser to set it manually:<br><code>https://api.telegram.org/bot' . htmlspecialchars($token) . '/setWebhook?url=' . urlencode($webhook_url) . '</code>';
                } else {
                    $botname = $me['result']['username'] ?? 'yourbot';
                    $success = "Installation complete! Start your bot: <a href='https://t.me/$botname' target='_blank'>@$botname</a>";
                }
            }
        }
    }
}
?><html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bot Install</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#212121;color:#e0e0e0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.card{background:#2b2b2b;border-radius:16px;padding:30px;width:100%;max-width:460px;box-shadow:0 8px 32px rgba(0,0,0,.4)}
h1{font-size:22px;color:#fff;margin-bottom:6px;display:flex;align-items:center;gap:8px}
h1 span{font-size:26px}
.sub{color:#aaa;font-size:13px;margin-bottom:24px}
label{display:block;font-size:13px;font-weight:600;color:#ccc;margin-bottom:5px;margin-top:16px}
input[type=text],input[type=password]{width:100%;padding:12px 14px;border:1px solid #444;border-radius:10px;font-size:15px;background:#1e1e1e;color:#fff;outline:none;transition:border .2s}
input:focus{border-color:#5b9aff}
.btn{width:100%;padding:13px;border:none;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;margin-top:22px;transition:opacity .2s}
.btn-primary{background:#5b9aff;color:#fff}
.btn-primary:hover{opacity:.9}
.btn-primary:disabled{opacity:.5;cursor:not-allowed}
.error{background:#3d1f1f;color:#ff7b7b;padding:12px 16px;border-radius:10px;font-size:13px;margin-top:16px;border:1px solid #662222;line-height:1.5}
.success{background:#1a3d1f;color:#7bff7b;padding:12px 16px;border-radius:10px;font-size:13px;margin-top:16px;border:1px solid #226622}
.success a{color:#7bff7b;text-decoration:underline}
.hint{font-size:12px;color:#888;margin-top:4px}
.note{font-size:12px;color:#888;margin-top:18px;padding:12px;background:#1e1e1e;border-radius:10px;line-height:1.5}
code{background:#111;padding:2px 6px;border-radius:4px;font-size:12px;word-break:break-all}
.loading{display:none;text-align:center;margin-top:16px}
.loading span{display:inline-block;width:8px;height:8px;border-radius:50%;background:#5b9aff;margin:0 3px;animation:bounce 1.4s infinite ease-in-out both}
.loading span:nth-child(1){animation-delay:-.32s}
.loading span:nth-child(2){animation-delay:-.16s}
@keyframes bounce{0%,80%,100%{transform:scale(0)}40%{transform:scale(1)}}
</style>
</head>
<body>
<div class="card">
<h1><span>🤖</span> Bot Setup</h1>
<p class="sub">Configure your Free Fire Likes Generator bot</p>

<?php if ($error): ?>
<div class="error"><?=$error?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="success"><?=$success?></div>
<div style="margin-top:16px;text-align:center">
<a href="https://t.me/<?=htmlspecialchars($botname ?? 'yourbot')?>" target="_blank" style="color:#5b9aff;font-weight:600;text-decoration:none">Open in Telegram →</a>
</div>
<?php else: ?>
<form method="post" onsubmit="installBtn.disabled=true;loading.style.display='block'">
<input type="hidden" name="step" value="install">
<label>Bot Token</label>
<input type="password" name="token" value="<?=htmlspecialchars($token)?>" placeholder="123456:ABC-DEF1234ghIkl..." required>
<div class="hint">From <a href="https://t.me/BotFather" target="_blank" style="color:#5b9aff">@BotFather</a></div>
<button type="submit" id="installBtn" class="btn btn-primary">Install &amp; Set Webhook</button>
<div class="loading" id="loading"><span></span><span></span><span></span></div>
</form>
<div class="note">
<strong>What happens:</strong> Config is written, webhook is registered with Telegram, SQLite database is created. Your first /start user becomes admin.<br><br>
No ads. No referrals. Instant generation of 1000+ likes.
</div>
<?php endif; ?>
</div>
<script>document.querySelector('form')?.addEventListener('submit',function(){document.getElementById('loading').style.display='block'});</script>
</body>
</html>
