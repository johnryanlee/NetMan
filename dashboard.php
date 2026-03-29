<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

// Stats
$total_devices  = db_get('SELECT COUNT(*) AS n FROM devices')['n'] ?? 0;
$online_devices = db_get('SELECT COUNT(*) AS n FROM devices WHERE status = "online"')['n'] ?? 0;
$offline_devices= db_get('SELECT COUNT(*) AS n FROM devices WHERE status = "offline"')['n'] ?? 0;
$new_today      = db_get('SELECT COUNT(*) AS n FROM devices WHERE DATE(first_seen) = CURDATE()')['n'] ?? 0;

// Last scan
$last_scan = db_get('SELECT * FROM scan_jobs ORDER BY created_at DESC LIMIT 1');

// Recent devices (last 10 seen)
$recent_devices = db_all(
    'SELECT ip_address, hostname, vendor, status, last_seen, first_seen, open_ports
     FROM devices ORDER BY last_seen DESC LIMIT 10'
);

// Running scan (if any)
$running_scan = db_get('SELECT * FROM scan_jobs WHERE status IN ("pending","running") ORDER BY created_at DESC LIMIT 1');

$scan_range = get_setting('scan_range', 'Not configured');

$page_title = 'Dashboard';
$show_nav = true;
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-subtitle">Probe overview &mdash; <span class="text-muted"><?= h($scan_range) ?></span></p>
    </div>
    <div class="page-actions">
        <a href="/scan.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            New Scan
        </a>
    </div>
</div>

<!-- Stat cards -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon--blue">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
        </div>
        <div class="stat-body">
            <div class="stat-value"><?= h($total_devices) ?></div>
            <div class="stat-label">Total Devices</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon--green">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div class="stat-body">
            <div class="stat-value"><?= h($online_devices) ?></div>
            <div class="stat-label">Online Now</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon--red">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div class="stat-body">
            <div class="stat-value"><?= h($offline_devices) ?></div>
            <div class="stat-label">Offline</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon--yellow">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <div class="stat-body">
            <div class="stat-value"><?= h($new_today) ?></div>
            <div class="stat-label">New Today</div>
        </div>
    </div>
</div>

<!-- Running scan banner -->
<?php if ($running_scan): ?>
<div class="alert alert-info scan-running-banner" id="scanBanner"
     data-job-id="<?= h($running_scan['id']) ?>">
    <div class="scan-spinner"></div>
    <span>Scan in progress on <strong><?= h($running_scan['target_range']) ?></strong> &mdash; started <?= time_ago($running_scan['started_at'] ?? $running_scan['created_at']) ?></span>
    <button onclick="document.getElementById('scanBanner').remove()" class="btn btn-ghost btn-sm" style="margin-left:auto">Dismiss</button>
</div>
<?php endif; ?>

<div class="two-col">
    <!-- Recent devices -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Recently Seen Devices</h2>
            <a href="/devices.php" class="btn btn-ghost btn-sm">View All</a>
        </div>
        <div class="card-body p-0">
            <?php if (empty($recent_devices)): ?>
            <div class="empty-state">
                <p>No devices discovered yet. <a href="/scan.php">Run a scan</a> to get started.</p>
            </div>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>IP Address</th>
                        <th>Hostname / Vendor</th>
                        <th>Status</th>
                        <th>Last Seen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_devices as $d): ?>
                    <tr>
                        <td><a href="/devices.php?ip=<?= urlencode($d['ip_address']) ?>" class="ip-link"><?= h($d['ip_address']) ?></a></td>
                        <td>
                            <?php if ($d['hostname']): ?>
                                <span class="hostname"><?= h($d['hostname']) ?></span><br>
                            <?php endif; ?>
                            <?php if ($d['vendor']): ?>
                                <span class="text-muted text-sm"><?= h($d['vendor']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge--<?= h($d['status']) ?>"><?= h($d['status']) ?></span></td>
                        <td class="text-muted text-sm"><?= time_ago($d['last_seen']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Last scan info -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Last Scan</h2>
            <a href="/scan.php" class="btn btn-ghost btn-sm">Scan History</a>
        </div>
        <div class="card-body">
            <?php if ($last_scan): ?>
            <dl class="def-list">
                <dt>Range</dt>
                <dd><?= h($last_scan['target_range']) ?></dd>
                <dt>Type</dt>
                <dd><?= h(ucfirst($last_scan['scan_type'])) ?></dd>
                <dt>Status</dt>
                <dd><span class="badge badge--<?= $last_scan['status'] === 'completed' ? 'online' : ($last_scan['status'] === 'failed' ? 'offline' : 'unknown') ?>"><?= h($last_scan['status']) ?></span></dd>
                <dt>Devices Found</dt>
                <dd><?= h($last_scan['devices_found']) ?> (<?= h($last_scan['devices_new']) ?> new)</dd>
                <dt>Started</dt>
                <dd><?= $last_scan['started_at'] ? h(date('Y-m-d H:i', strtotime($last_scan['started_at']))) : '&mdash;' ?></dd>
                <dt>Duration</dt>
                <?php
                if ($last_scan['started_at'] && $last_scan['completed_at']) {
                    $dur = strtotime($last_scan['completed_at']) - strtotime($last_scan['started_at']);
                    echo "<dd>{$dur}s</dd>";
                } else {
                    echo '<dd>&mdash;</dd>';
                }
                ?>
            </dl>
            <?php else: ?>
            <div class="empty-state"><p>No scans have been run yet.</p></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$inline_script = "
if (document.getElementById('scanBanner')) {
    setInterval(function() {
        fetch('/api/scan_status.php?job_id=" . ($running_scan['id'] ?? 0) . "')
            .then(r => r.json())
            .then(d => { if (d.status === 'completed' || d.status === 'failed') location.reload(); });
    }, 5000);
}
";
include __DIR__ . '/includes/footer.php';
?>
