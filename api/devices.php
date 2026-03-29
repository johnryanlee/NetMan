<?php
// GET /api/devices.php  — JSON list of all devices (for future JS data tables)
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

$status = $_GET['status'] ?? '';
$q      = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($status && in_array($status, ['online','offline','unknown'])) {
    $where[] = 'status = ?';
    $params[] = $status;
}
if ($q) {
    $where[] = '(ip_address LIKE ? OR hostname LIKE ? OR vendor LIKE ? OR mac_address LIKE ?)';
    $like = "%$q%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$sql = 'SELECT id, ip_address, mac_address, hostname, vendor, device_type,
               custom_name, status, open_ports, first_seen, last_seen
        FROM devices WHERE ' . implode(' AND ', $where) . '
        ORDER BY INET_ATON(ip_address) ASC LIMIT 1000';

$rows = db_all($sql, $params);

// Decode open_ports JSON for each row
foreach ($rows as &$row) {
    $row['open_ports'] = json_decode($row['open_ports'] ?? '[]', true) ?? [];
}

json_response(['devices' => $rows, 'count' => count($rows)]);
