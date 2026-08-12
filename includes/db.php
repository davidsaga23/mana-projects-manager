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
