<?php
// Database connection helper

function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $config_file = dirname(__DIR__) . '/config.php';
    if (!file_exists($config_file)) {
        throw new RuntimeException('Configuration file not found. Please run the installer.');
    }
    require_once $config_file;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}

function db_get(string $sql, array $params = []): array|false {
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch();
}

function db_all(string $sql, array $params = []): array {
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_run(string $sql, array $params = []): int {
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return (int) get_db()->lastInsertId();
}

function db_exec(string $sql, array $params = []): int {
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function get_setting(string $key, string $default = ''): string {
    $row = db_get('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
    return $row ? (string)$row['setting_value'] : $default;
}

function set_setting(string $key, string $value): void {
    db_run(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$key, $value]
    );
}
