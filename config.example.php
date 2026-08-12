<?php
// -----------------------------------------------------------------------------
// Anima – CONFIG
// Copy this file to `config.php` on the SERVER and fill in the real values.
// `config.php` is excluded from git and from the deploy, so it is never
// overwritten by a push. When a new setting is added here, add the same line
// to the live config.php by hand.
// -----------------------------------------------------------------------------

return [
    // MySQL connection (from cPanel > MySQL Databases)
    'db_host'     => 'localhost',
    'db_name'     => 'yourcpaneluser_manaplatform',
    'db_user'     => 'yourcpaneluser_manauser',
    'db_pass'     => 'YOUR_STRONG_DB_PASSWORD',
    'db_charset'  => 'utf8mb4',

    // Session / security
    'session_name'   => 'mana_platform',
    'session_secure' => true,   // set to false only if the site does NOT use HTTPS

    // How long a login lasts, in seconds. Rolling window: resets on every page
    // view, so you're only logged out after being idle the whole period.
    // Sessions live in the app's own /sessions directory so neighbouring sites
    // on shared hosting can't garbage-collect them early.
    //   28800 = 8 hours   604800 = 7 days (default)   2592000 = 30 days
    'session_lifetime' => 604800,

    // Timezone — affects "today" everywhere (snapshots, quotas, schedules)
    'timezone' => 'America/Mexico_City',

    // -------------------------------------------------------------------------
    // Email (notifications) — same rules as STFC:
    // PHP mail() cannot authenticate, so send through a real mailbox.
    // Port and encryption MUST match: 465 = 'ssl', 587 = 'tls'. Mixing them hangs.
    // cPanel mail servers often have self-signed certs -> smtp_verify_peer false.
    // Leave smtp_host empty to disable email entirely.
    // -------------------------------------------------------------------------
    'smtp_host'      => '',
    'smtp_port'      => 465,
    'smtp_secure'    => 'ssl',               // 'ssl' for 465, 'tls' for 587
    'smtp_user'      => '',
    'smtp_pass'      => '',
    'smtp_ehlo'      => '',
    'smtp_verify_peer' => false,
    'mail_from'      => '',
    'mail_from_name' => 'Anima',
    'mail_reply_to'  => 'david@mana.tv',

    // -------------------------------------------------------------------------
    // Attachments (used from the tickets phase onward)
    // -------------------------------------------------------------------------
    'upload_max_bytes' => 25 * 1024 * 1024,  // 25 MB per file
    'upload_max_files' => 5,
];
