<?php
// GET /api/scan_status.php?job_id=N
// Returns JSON status of a scan job (used by dashboard/scan page polling)
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_login();

$job_id = (int)($_GET['job_id'] ?? 0);
if (!$job_id) json_response(['error' => 'Missing job_id'], 400);

$job = db_get('SELECT * FROM scan_jobs WHERE id = ?', [$job_id]);
if (!$job) json_response(['error' => 'Job not found'], 404);

json_response([
    'id'             => (int)$job['id'],
    'status'         => $job['status'],
    'scan_type'      => $job['scan_type'],
    'target_range'   => $job['target_range'],
    'devices_found'  => (int)$job['devices_found'],
    'devices_new'    => (int)$job['devices_new'],
    'started_at'     => $job['started_at'],
    'completed_at'   => $job['completed_at'],
    'error_message'  => $job['error_message'],
]);
