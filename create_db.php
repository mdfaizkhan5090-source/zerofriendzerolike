<?php
// One-time database creator. Open this once in browser, then delete the file.
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

echo "✅ Database created successfully.<br>You can now delete this file (create_db.php).";
