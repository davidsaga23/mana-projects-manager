<?php
// DB connection (PDO). Included by every page.

$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['timezone']);

try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $config['db_host'],
        $config['db_name'],
        $config['db_charset']
    );
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die('Database connection failed. Check config.php credentials.');
}

// ⚠ ONE CLOCK. PHP reads 'timezone' from config.php, but MySQL runs on the
// host's clock, and on the shared host the two were six hours apart. Every
// NOW() and CURDATE() then disagreed with PHP's date(): in the evening MySQL was
// already on tomorrow, so a timer started at 7 pm was "in the future" to PHP and
// its hours were refused. Telling MySQL PHP's own UTC offset makes them one
// clock, whichever zone config.php names.
//
// An OFFSET rather than the zone's name, because named zones need MySQL's
// timezone tables, which shared hosts usually never load. The offset is taken
// fresh on every request, so daylight saving moves both clocks together.
//
// TIMESTAMP columns (created_at and the like) are stored in UTC and read back in
// this offset, so they now display in local time, which is what they always
// meant. DATETIME values written with NOW() before this change keep the host
// time they were written with.
try {
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $e) {
    // Never worth a white page: the app ran on two clocks before this line.
}

// Per-project settings (key/value). Global config lives in config.php only.
function project_setting(PDO $pdo, int $projectId, string $key, $default = null) {
    $stmt = $pdo->prepare(
        'SELECT setting_value FROM project_settings WHERE project_id = ? AND setting_key = ?'
    );
    $stmt->execute([$projectId, $key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function set_project_setting(PDO $pdo, int $projectId, string $key, string $value): void {
    $stmt = $pdo->prepare(
        'INSERT INTO project_settings (project_id, setting_key, setting_value) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$projectId, $key, $value]);
}
