<?php
// Entry point — redirect based on install/auth state
$config_file = __DIR__ . '/config.php';

if (!file_exists($config_file)) {
    header('Location: /install.php');
    exit;
}

require_once $config_file;

if (!defined('APP_INSTALLED') || APP_INSTALLED !== true) {
    header('Location: /install.php');
    exit;
}

header('Location: /dashboard.php');
exit;
