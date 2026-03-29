<?php
// POST /api/settings.php — update probe settings (admin only)
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'POST required'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_response(['error' => 'Invalid JSON body'], 400);
}

$allowed_keys = ['scan_range', 'app_name', 'scan_interval', 'max_scan_history'];
$updated = [];

foreach ($data as $key => $value) {
    if (!in_array($key, $allowed_keys, true)) continue;

    if ($key === 'scan_range' && !valid_cidr($value)) {
        json_response(['error' => 'Invalid CIDR range for scan_range'], 422);
    }

    set_setting($key, (string)$value);
    $updated[] = $key;
}

json_response(['updated' => $updated]);
