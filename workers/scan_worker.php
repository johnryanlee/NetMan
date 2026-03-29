#!/usr/bin/env php
<?php
/**
 * NetMan Scan Worker
 * Runs as a background CLI process: php scan_worker.php <job_id>
 *
 * This script is launched by scan.php and must NOT be web-accessible.
 * Apache/.htaccess blocks direct access to /workers/.
 */

// Sanity checks
if (php_sapi_name() !== 'cli') {
    exit("This script must be run from the command line.\n");
}

$job_id = (int)($argv[1] ?? 0);
if (!$job_id) {
    exit("Usage: php scan_worker.php <job_id>\n");
}

// Bootstrap
define('WORKER_RUNNING', true);
$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/functions.php';

// ── Fetch the job ─────────────────────────────────────────────────────────
$job = db_get('SELECT * FROM scan_jobs WHERE id = ? AND status = "pending"', [$job_id]);
if (!$job) {
    exit("Job #$job_id not found or not in pending state.\n");
}

$range     = $job['target_range'];
$scan_type = $job['scan_type'];
$log       = fn(string $msg) => print(date('[Y-m-d H:i:s] ') . $msg . "\n");

// Mark as running
db_exec(
    'UPDATE scan_jobs SET status="running", started_at=NOW() WHERE id=?',
    [$job_id]
);

$log("Starting $scan_type scan on $range (job #$job_id)");

// ── Build nmap command ────────────────────────────────────────────────────
$nmap = get_setting('nmap_path', '/usr/bin/nmap');
if (!is_executable($nmap)) {
    $nmap = trim(shell_exec('which nmap 2>/dev/null') ?? '');
}
if (!$nmap || !is_executable($nmap)) {
    db_exec(
        'UPDATE scan_jobs SET status="failed", completed_at=NOW(),
         error_message=? WHERE id=?',
        ['nmap not found or not executable. Install nmap on the probe.', $job_id]
    );
    exit("nmap not found.\n");
}

$xml_file = sys_get_temp_dir() . "/netman_scan_{$job_id}.xml";

$nmap_flags = match ($scan_type) {
    'discovery' => '-sn --send-ip -T4',          // Ping sweep, no port scan
    'quick'     => '-F -sV -T4 --open',           // Top 100 ports
    'full'      => '-p- -sV -sC -T4 --open',      // All ports + scripts
    default     => '-sn -T4',
};

// Use sudo for raw socket access (configured in /etc/sudoers.d/netman-nmap)
$cmd = sprintf(
    'sudo %s %s -oX %s %s 2>&1',
    escapeshellarg($nmap),
    $nmap_flags,
    escapeshellarg($xml_file),
    escapeshellarg($range)
);

$log("Running: $cmd");

// Execute nmap
$output = [];
$exit_code = 0;
exec($cmd, $output, $exit_code);

foreach ($output as $line) {
    $log("nmap: $line");
}

if ($exit_code !== 0) {
    db_exec(
        'UPDATE scan_jobs SET status="failed", completed_at=NOW(),
         error_message=? WHERE id=?',
        ['nmap exited with code ' . $exit_code . '. Check worker log.', $job_id]
    );
    if (file_exists($xml_file)) unlink($xml_file);
    exit("nmap failed (exit $exit_code).\n");
}

// ── Parse results ─────────────────────────────────────────────────────────
$log("Parsing results from $xml_file");
$hosts = parse_nmap_xml($xml_file);
$log("Found " . count($hosts) . " hosts");

$scan_started = new DateTime();
$total_found  = 0;
$total_new    = 0;

foreach ($hosts as $host) {
    if (empty($host['ip_address'])) continue;
    $result = upsert_device($host);
    $total_found++;
    if ($result['new']) $total_new++;
    $log(($result['new'] ? '[NEW] ' : '      ') . $host['ip_address'] . ' ' . ($host['hostname'] ?? '') . ' ' . ($host['vendor'] ?? ''));
}

// Mark devices not seen in this scan as offline
// (only if it was a full network scan, not a targeted IP)
if (str_contains($range, '/') && $scan_type === 'discovery') {
    $offline_count = db_exec(
        'UPDATE devices SET status="offline"
         WHERE last_scan < NOW() - INTERVAL 10 MINUTE
           AND status = "online"'
    );
    if ($offline_count > 0) $log("Marked $offline_count device(s) offline.");
}

// ── Finalise job ──────────────────────────────────────────────────────────
db_exec(
    'UPDATE scan_jobs SET
        status="completed",
        completed_at=NOW(),
        devices_found=?,
        devices_new=?
     WHERE id=?',
    [$total_found, $total_new, $job_id]
);

// Prune old scan history
$max = (int)get_setting('max_scan_history', '50');
if ($max > 0) {
    $ids = db_all(
        'SELECT id FROM scan_jobs ORDER BY created_at DESC LIMIT 99999 OFFSET ?',
        [$max]
    );
    foreach ($ids as $old) {
        db_exec('DELETE FROM scan_jobs WHERE id=?', [$old['id']]);
    }
}

// Cleanup
if (file_exists($xml_file)) unlink($xml_file);

$log("Scan #$job_id complete. Found: $total_found, New: $total_new");
exit(0);
