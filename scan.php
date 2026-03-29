<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$error = '';
$success = '';
$scan_range = get_setting('scan_range', '');

// ── Trigger new scan ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_scan') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } elseif (!is_admin()) {
        $error = 'Only admins can trigger scans.';
    } else {
        $range     = trim($_POST['scan_range'] ?? $scan_range);
        $scan_type = $_POST['scan_type'] ?? 'discovery';

        if (!valid_cidr($range)) {
            $error = 'Invalid CIDR range.';
        } elseif (!in_array($scan_type, ['discovery', 'quick', 'full'])) {
            $error = 'Invalid scan type.';
        } else {
            // Check no scan already running
            $running = db_get('SELECT id FROM scan_jobs WHERE status IN ("pending","running")');
            if ($running) {
                $error = 'A scan is already in progress. Please wait for it to complete.';
            } else {
                $user = current_user();
                $job_id = db_run(
                    'INSERT INTO scan_jobs (scan_type, target_range, status, created_by) VALUES (?,?,"pending",?)',
                    [$scan_type, $range, $user['id']]
                );

                // Launch background worker (non-blocking)
                $worker = escapeshellarg(__DIR__ . '/workers/scan_worker.php');
                $log    = escapeshellarg(__DIR__ . '/storage/scan_' . $job_id . '.log');
                $cmd    = "php $worker $job_id > $log 2>&1 &";
                exec($cmd);

                $success = "Scan job #$job_id started on $range.";
            }
        }
    }
}

// ── Scan history ────────────────────────────────────────────────────────────
$history = db_all(
    'SELECT sj.*, u.username FROM scan_jobs sj
     LEFT JOIN users u ON u.id = sj.created_by
     ORDER BY sj.created_at DESC LIMIT 50'
);

$running_scan = db_get('SELECT * FROM scan_jobs WHERE status IN ("pending","running") ORDER BY created_at DESC LIMIT 1');

$page_title = 'Network Scan';
$show_nav = true;
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Network Scan</h1>
        <p class="page-subtitle">Discover and inventory devices on <strong><?= h($scan_range ?: 'unconfigured range') ?></strong></p>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-error"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<div class="two-col">
    <!-- Start scan form -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Start Scan</h2>
        </div>
        <div class="card-body">
            <?php if (!is_admin()): ?>
            <div class="alert alert-info">Only administrators can trigger scans.</div>
            <?php elseif ($running_scan): ?>
            <div class="alert alert-info scan-running-banner" id="scanBanner" data-job-id="<?= h($running_scan['id']) ?>">
                <div class="scan-spinner"></div>
                <span>A scan is already running on <strong><?= h($running_scan['target_range']) ?></strong>.</span>
            </div>
            <?php else: ?>
            <form method="POST" action="/scan.php">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="start_scan">

                <div class="form-group">
                    <label>Scan Range (CIDR)</label>
                    <input type="text" name="scan_range" class="form-control"
                        value="<?= h($scan_range) ?>"
                        placeholder="192.168.1.0/24" required>
                    <p class="form-help">The range saved during setup is pre-filled. You can override it for one-off scans.</p>
                </div>

                <div class="form-group">
                    <label>Scan Type</label>
                    <div class="scan-type-grid">
                        <label class="scan-type-card">
                            <input type="radio" name="scan_type" value="discovery" checked>
                            <div class="scan-type-body">
                                <strong>Discovery</strong>
                                <p>Ping sweep &amp; OS detection. Fast — typically 1–2 minutes.</p>
                            </div>
                        </label>
                        <label class="scan-type-card">
                            <input type="radio" name="scan_type" value="quick">
                            <div class="scan-type-body">
                                <strong>Quick</strong>
                                <p>Top 100 ports per host. Moderate — 3–10 minutes.</p>
                            </div>
                        </label>
                        <label class="scan-type-card">
                            <input type="radio" name="scan_type" value="full">
                            <div class="scan-type-body">
                                <strong>Full</strong>
                                <p>All 65535 ports + service detection. Slow — 15–60 minutes.</p>
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-full" id="startScanBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    Start Scan
                </button>
            </form>
            <?php endif; ?>

            <!-- Scan type info -->
            <div class="scan-info-box" style="margin-top:1.5rem;">
                <h4>About nmap Scans</h4>
                <ul>
                    <li><strong>Discovery</strong> uses <code>nmap -sn -O</code> (no port scan, OS detection)</li>
                    <li><strong>Quick</strong> uses <code>nmap -F -sV</code> (top 100 ports + service version)</li>
                    <li><strong>Full</strong> uses <code>nmap -p- -sV -sC</code> (all ports + scripts)</li>
                    <li>MAC addresses are only visible for devices on the same L2 segment</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Scan history -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Scan History</h2>
        </div>
        <div class="card-body p-0">
            <?php if (empty($history)): ?>
            <div class="empty-state"><p>No scans have been run yet.</p></div>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Range</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Found</th>
                        <th>Started</th>
                    </tr>
                </thead>
                <tbody id="scanHistoryBody">
                    <?php foreach ($history as $job): ?>
                    <tr id="job-<?= h($job['id']) ?>">
                        <td class="text-muted text-sm"><?= h($job['id']) ?></td>
                        <td class="text-mono text-sm"><?= h($job['target_range']) ?></td>
                        <td><?= h(ucfirst($job['scan_type'])) ?></td>
                        <td>
                            <span class="badge badge--<?= $job['status'] === 'completed' ? 'online' : ($job['status'] === 'failed' ? 'offline' : 'unknown') ?>">
                                <?= h($job['status']) ?>
                            </span>
                        </td>
                        <td><?= $job['status'] === 'completed' ? h($job['devices_found']) . ' (' . h($job['devices_new']) . ' new)' : '&mdash;' ?></td>
                        <td class="text-sm text-muted"><?= time_ago($job['started_at'] ?? $job['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$running_id = $running_scan['id'] ?? 0;
$inline_script = "
// Poll running scan status
(function() {
    var jobId = $running_id;
    if (!jobId) return;
    var banner = document.getElementById('scanBanner');
    var interval = setInterval(function() {
        fetch('/api/scan_status.php?job_id=' + jobId)
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.status === 'completed' || d.status === 'failed') {
                    clearInterval(interval);
                    location.reload();
                }
            });
    }, 4000);
})();

// Confirm on start
var scanForm = document.querySelector('form[action=\"/scan.php\"]');
if (scanForm) {
    scanForm.addEventListener('submit', function(e) {
        var btn = document.getElementById('startScanBtn');
        btn.disabled = true;
        btn.textContent = 'Launching scan\u2026';
    });
}
";
include __DIR__ . '/includes/footer.php';
?>
