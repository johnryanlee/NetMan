<?php
// Authentication helpers

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ]);
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_token']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function current_user(): array|null {
    if (!is_logged_in()) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'role'     => $_SESSION['user_role'],
    ];
}

function login_user(string $username, string $password): bool {
    require_once __DIR__ . '/db.php';
    $user = db_get('SELECT * FROM users WHERE username = ?', [$username]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_token'] = bin2hex(random_bytes(16));

    db_exec('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
    return true;
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function is_admin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('Access denied.');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
