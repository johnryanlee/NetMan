<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title ?? 'NetMan') ?> &mdash; NetMan</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
</head>
<body>

<?php if (!empty($show_nav)): ?>
<?php $user = current_user(); ?>
<header class="topbar">
    <div class="topbar-brand">
        <svg class="brand-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="3"/>
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
            <path d="M4.93 4.93l4.24 4.24M14.83 14.83l4.24 4.24M4.93 19.07l4.24-4.24M14.83 9.17l4.24-4.24"/>
        </svg>
        <span>NetMan</span>
    </div>

    <nav class="topbar-nav">
        <a href="/dashboard.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            Dashboard
        </a>
        <a href="/devices.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'devices.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
            Devices
        </a>
        <a href="/scan.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'scan.php' ? 'active' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Scan
        </a>
    </nav>

    <div class="topbar-user">
        <span class="user-badge"><?= h($user['username'] ?? '') ?></span>
        <a href="/logout.php" class="btn btn-ghost btn-sm">Logout</a>
    </div>
</header>

<div class="layout">
    <main class="main-content">
<?php endif; ?>
