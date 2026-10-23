<?php
// -----------------------------------------------------------------------------
// Anima | Project manager – CONFIG
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

    // Timezone — affects "today" everywhere (snapshots, quotas, schedules), for
    // PHP and MySQL alike (includes/db.php hands MySQL the same offset).
    // Pacific time, David 2026-10-16. America/Los_Angeles follows US daylight
    // saving (PST in winter, PDT in summer).
    'timezone' => 'America/Los_Angeles',

    // -------------------------------------------------------------------------
    // Anthropic API — reading a call transcript into tasks (transcript.php)
    // -------------------------------------------------------------------------
    // The ONLY thing in this platform that sends anything outside this server.
    // Leave it out and the Transcript screen says so and does nothing else; no
    // other screen is affected.
    //
    // ⚠ A TRANSCRIPT LEAVES THE BUILDING when this is set, and it costs a small
    // amount per use. Both are facts about the studio, not about the code, which
    // is why this is a line somebody adds on purpose rather than a default.
    //
    // Get a key from console.anthropic.com. It is a secret: config.php is out of
    // git and out of the deploy, so it only ever exists on the server.
    // 'anthropic_api_key' => 'sk-ant-...',

    // -------------------------------------------------------------------------
    // Email (ticket notifications)
    // -------------------------------------------------------------------------
    // Sent through a real authenticated mailbox. PHP's mail() cannot
    // authenticate, so anything it sends claiming to be from another domain
    // fails SPF/DMARC and gets spam-foldered (START_HERE, lesson 10).
    //
    // THE SENDING STRATEGY, CARRIED OVER FROM STFC — this is the part worth
    // reading, because it looks wrong until you know why:
    //
    //   Mail is sent AS hi@davidsaga.com — a cPanel mailbox on this same server,
    //   so SPF and DKIM align and the message lands in inboxes — with Reply-To
    //   pointing at david@mana.tv so replies reach Mana.
    //
    //   Sending directly as @mana.tv would need a Google App Password, which
    //   requires 2-Step Verification, which is currently disabled org-wide by
    //   the Workspace admin. If that ever changes, sending as @mana.tv directly
    //   becomes possible and this indirection can go.
    //
    // Both apps use the SAME mailbox, so no new account is needed here — copy
    // smtp_pass across from STFC's config.php. Only mail_from_name differs, and
    // it differs on purpose: it is what recipients see as the sender.
    //
    // Exact host/port for the mailbox: cPanel > Email Accounts > "Connect
    // Devices" on that address. Or open smtp_test.php in the app, which probes
    // every plausible combination and tells you which one authenticates.
    //
    // Port and encryption MUST match: 465 = 'ssl' (implicit), 587 = 'tls'
    // (STARTTLS). Mixing them does not error — it hangs until the timeout,
    // because the server expects a TLS handshake and the client starts in
    // plain text.
    //
    // Leave smtp_host EMPTY to disable email entirely. Tickets still work;
    // every screen then says notifications are switched off rather than
    // pretending to send.
    // -------------------------------------------------------------------------
    'smtp_host'      => 'davidsaga.com',     // per cPanel > Email Accounts > Connect Devices
    'smtp_port'      => 465,
    'smtp_secure'    => 'ssl',               // 'ssl' for 465, 'tls' for 587
    'smtp_user'      => 'hi@davidsaga.com',
    'smtp_pass'      => '',                  // the mailbox password — the ONLY secret here
    'smtp_ehlo'      => 'davidsaga.com',
    // cPanel mail servers often present a self-signed cert; set false if TLS fails.
    'smtp_verify_peer' => false,
    'mail_from'      => 'hi@davidsaga.com',
    'mail_from_name' => 'Anima | Project manager',
    'mail_reply_to'  => 'david@mana.tv',     // replies go to Mana, not the sending box

    // -------------------------------------------------------------------------
    // FINANCE DATABASE — pay rates and contract links. Optional.
    // -------------------------------------------------------------------------
    // A SEPARATE database with a SEPARATE MySQL user. The point is that the
    // production user above has no rights here at all, so a bug in any ordinary
    // query on any ordinary screen cannot reach a rate — the connection it runs
    // on is not permitted to see one. Only includes/money.php opens this, and
    // only behind an is_admin() check.
    //
    // Set it up with finance_install.sql, which documents the cPanel steps.
    // Leave db_name empty to switch the feature off: the person page then says
    // it is not configured rather than showing a blank where a rate would be.
    //
    // Do NOT reuse the production password here. If both credentials are the
    // same, an attacker who learns one has both, and the separation is theatre.
    'finance_db' => [
        'db_host'    => 'localhost',
        'db_name'    => '',                  // e.g. yourcpaneluser_manafinance
        'db_user'    => '',                  // e.g. yourcpaneluser_finance
        'db_pass'    => '',
        'db_charset' => 'utf8mb4',
    ],

    // -------------------------------------------------------------------------
    // Attachments (used from the tickets phase onward)
    // -------------------------------------------------------------------------
    'upload_max_bytes' => 25 * 1024 * 1024,  // 25 MB per file
    'upload_max_files' => 5,

    // -------------------------------------------------------------------------
    // Bug export (bugs_export.php), read by tools/pull_bugs.py so Claude can
    // work through the bug reports. A long random secret, 32+ characters; the
    // same value goes in tools/.bug_key on the machine that pulls. Leave it
    // empty and the export is switched off.
    // -------------------------------------------------------------------------
    'bug_export_key' => '',
];
