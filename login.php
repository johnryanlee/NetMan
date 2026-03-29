<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';
$redirect = filter_var($_GET['redirect'] ?? '/dashboard.php', FILTER_SANITIZE_URL);
// Only allow relative paths
if (!str_starts_with($redirect, '/')) $redirect = '/dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (login_user($username, $password)) {
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$page_title = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &mdash; NetMan</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="install-body">

<div class="install-container install-container--sm">
    <div class="install-header">
        <svg class="install-logo" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="24" cy="24" r="6"/>
            <circle cx="24" cy="24" r="16" stroke-dasharray="4 4"/>
            <path d="M24 8v4M24 36v4M8 24h4M36 24h4"/>
            <circle cx="24" cy="8" r="2" fill="currentColor" stroke="none"/>
            <circle cx="24" cy="40" r="2" fill="currentColor" stroke="none"/>
            <circle cx="8" cy="24" r="2" fill="currentColor" stroke="none"/>
            <circle cx="40" cy="24" r="2" fill="currentColor" stroke="none"/>
        </svg>
        <h1>NetMan</h1>
        <p>Network Management Probe</p>
    </div>

    <div class="install-card">
        <h2>Sign In</h2>

        <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="/login.php?redirect=<?= urlencode($redirect) ?>">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus
                    class="form-control" autocomplete="username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required
                    class="form-control" autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-full">Sign In</button>
        </form>
    </div>
</div>

</body>
</html>
