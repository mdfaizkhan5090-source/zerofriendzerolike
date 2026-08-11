# Free Fire Likes Generator — Telegram Bot

A clean PHP Telegram bot that generates Free Fire likes instantly. No ads. No referrals. No waiting.

## Features

- Telegram Bot with inline keyboard menu
- Instant generation (no Mini App / no ad wall)
- Minimum **1000+ likes** every time
- SQLite storage (user tracking, first-user admin)
- Broadcast system for admin messages
- Web-based installer + manual fallback

## Files

| File | Purpose |
|------|---------|
| `install.php` | Web installer — enter bot token |
| `config.php` | Bot configuration (already filled with your token) |
| `bot.php` | Webhook handler — all bot logic |
| `db.php` | SQLite database helper |
| `create_db.php` | One-time database creator (delete after use) |
| `set_webhook.php` | Manual webhook registration |
| `broadcast.php` | Web-based admin broadcast tool |

## Requirements

- PHP 8.0+ with `pdo_sqlite` extension
- HTTPS-enabled web server
- Telegram Bot Token (from [@BotFather](https://t.me/BotFather))

## Quick Setup (Recommended)

Your token is already written into `config.php`.

1. Upload **all files** to your PHP hosting
2. Open `create_db.php` in browser → should say "Database created successfully"
3. Delete `create_db.php` after that
4. Open `set_webhook.php` in browser
5. If it fails, copy the long `api.telegram.org` link it shows and open it in your browser
6. Start the bot on Telegram: [@zerofriendzerolike_bot](https://t.me/zerofriendzerolike_bot)
7. Send `/start` — you become admin

## Manual Webhook (if set_webhook.php fails)

Replace `YOUR-DOMAIN.com/path` with your real URL:

```
https://api.telegram.org/bot8847236768:AAFCuxhfFt6rO4b_7y6_SRPyG2Xv2XyQki8/setWebhook?url=https://YOUR-DOMAIN.com/path/bot.php
```

You should see: `{"ok":true,"result":true,"description":"Webhook was set"}`

## How it works

1. User taps **Generate Likes**
2. Sends Free Fire UID
3. Enters number of likes (min 1000, or 0 for random 1000–2500)
4. Instant success card — no ads, no referrals, no extra steps

## Commands

| Command | Access | Action |
|---------|--------|--------|
| `/start` | All users | Show main menu |
| `/broadcast` | Admin only | Send message to all users |

## Important

- Site **must** be HTTPS (Telegram rejects HTTP webhooks)
- First user who sends `/start` becomes admin
- If your host blocks outbound Telegram API, use the manual webhook link above

## License

MIT
