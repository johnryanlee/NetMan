<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

// ── Handle edit/save (admin only) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $form_error = 'Invalid request token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_device') {
            $id          = (int)$_POST['device_id'];
            $custom_name = trim($_POST['custom_name'] ?? '');
            $device_type = trim($_POST['device_type'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');
            db_exec(
                'UPDATE devices SET custom_name=?, device_type=?, notes=? WHERE id=?',
                [$custom_name ?: null, $device_type ?: null, $notes ?: null, $id]
            );
            $form_success = 'Device updated.';
        }
    }
}

// ── Filters ─────────────────────────────────────────────────────────────────
$filter_status = $_GET['status'] ?? '';
$filter_search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;

$where = ['1=1'];
$params = [];

if ($filter_status && in_array($filter_status, ['online', 'offline', 'unknown'])) {
    $where[] = 'status = ?';
    $params[] = $filter_status;
}
if ($filter_search) {
    $where[] = '(ip_address LIKE ? OR hostname LIKE ? OR vendor LIKE ? OR custom_name LIKE ? OR mac_address LIKE ?)';
    $like = "%$filter_search%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$where_sql = implode(' AND ', $where);
$total = (int)(db_get("SELECT COUNT(*) AS n FROM devices WHERE $where_sql", $params)['n'] ?? 0);
$pages = max(1, (int)ceil($total / $per_page));
$offset = ($page - 1) * $per_page;

$devices = db_all(
    "SELECT * FROM devices WHERE $where_sql ORDER BY
        INET_ATON(ip_address) ASC
     LIMIT $per_page OFFSET $offset",
    $params
);

// Single device detail view
$detail_device = null;
if (!empty($_GET['ip'])) {
    $detail_device = db_get('SELECT * FROM devices WHERE ip_address = ?', [trim($_GET['ip'])]);
}

$page_title = 'Devices';
$show_nav = true;
include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Devices</h1>
        <p class="page-subtitle"><?= h($total) ?> device<?= $total !== 1 ? 's' : '' ?> found</p>
    </div>
    <div class="page-actions">
        <a href="/scan.php" class="btn btn-primary">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            New Scan
        </a>
    </div>
</div>

<?php if (!empty($form_error)): ?>
<div class="alert alert-error"><?= h($form_error) ?></div>
<?php endif; ?>
<?php if (!empty($form_success)): ?>
<div class="alert alert-success"><?= h($form_success) ?></div>
<?php endif; ?>

<!-- Filters bar -->
<div class="filter-bar">
    <form method="GET" action="/devices.php" class="filter-form">
        <input type="text" name="q" value="<?= h($filter_search) ?>"
            placeholder="Search IP, hostname, vendor, MAC..."
            class="form-control filter-search">
        <select name="status" class="form-control filter-select">
            <option value="">All Status</option>
            <option value="online"  <?= $filter_status === 'online'  ? 'selected' : '' ?>>Online</option>
            <option value="offline" <?= $filter_status === 'offline' ? 'selected' : '' ?>>Offline</option>
            <option value="unknown" <?= $filter_status === 'unknown' ? 'selected' : '' ?>>Unknown</option>
        </select>
        <button type="submit" class="btn btn-secondary">Filter</button>
        <?php if ($filter_search || $filter_status): ?>
        <a href="/devices.php" class="btn btn-ghost">Clear</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="card-body p-0">
        <?php if (empty($devices)): ?>
        <div class="empty-state">
            <p>No devices match your search. <a href="/scan.php">Run a scan</a> to discover devices.</p>
        </div>
        <?php else: ?>
        <table class="table table--hoverable">
            <thead>
                <tr>
                    <th>IP Address</th>
                    <th>Name / Hostname</th>
                    <th>MAC Address</th>
                    <th>Vendor</th>
                    <th>Open Ports</th>
                    <th>Status</th>
                    <th>Last Seen</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $d): ?>
                <tr class="device-row <?= $detail_device && $detail_device['id'] == $d['id'] ? 'row--selected' : '' ?>">
                    <td>
                        <a href="/devices.php?ip=<?= urlencode($d['ip_address']) ?>#detail" class="ip-link">
                            <?= h($d['ip_address']) ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($d['custom_name']): ?>
                            <strong><?= h($d['custom_name']) ?></strong><br>
                        <?php endif; ?>
                        <span class="text-sm text-muted"><?= h($d['hostname'] ?? '—') ?></span>
                    </td>
                    <td class="text-mono text-sm"><?= h($d['mac_address'] ?? '—') ?></td>
                    <td class="text-sm"><?= h($d['vendor'] ?? '—') ?></td>
                    <td class="text-sm">
                        <?php
                        $ports = json_decode($d['open_ports'] ?? '[]', true);
                        if (!empty($ports)) {
                            $port_list = array_slice(array_map(fn($p) => $p['port'], $ports), 0, 5);
                            echo h(implode(', ', $port_list));
                            if (count($ports) > 5) echo ' <span class="text-muted">+' . (count($ports)-5) . ' more</span>';
                        } else {
                            echo '<span class="text-muted">—</span>';
                        }
                        ?>
                    </td>
                    <td><span class="badge badge--<?= h($d['status']) ?>"><?= h($d['status']) ?></span></td>
                    <td class="text-sm text-muted"><?= time_ago($d['last_seen']) ?></td>
                    <td>
                        <a href="/devices.php?ip=<?= urlencode($d['ip_address']) ?>#detail" class="btn btn-ghost btn-xs">Details</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page-1 ?>&q=<?= urlencode($filter_search) ?>&status=<?= urlencode($filter_status) ?>" class="btn btn-ghost btn-sm">&larr; Prev</a>
            <?php endif; ?>
            <span class="pagination-info">Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?>
            <a href="?page=<?= $page+1 ?>&q=<?= urlencode($filter_search) ?>&status=<?= urlencode($filter_status) ?>" class="btn btn-ghost btn-sm">Next &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Device detail panel -->
<?php if ($detail_device): ?>
<div class="card" id="detail" style="margin-top:2rem;">
    <div class="card-header">
        <h2 class="card-title">
            <?= h($detail_device['custom_name'] ?: $detail_device['hostname'] ?: $detail_device['ip_address']) ?>
            <span class="badge badge--<?= h($detail_device['status']) ?> ml-2"><?= h($detail_device['status']) ?></span>
        </h2>
        <?php if (is_admin()): ?>
        <button onclick="document.getElementById('editForm').classList.toggle('hidden')" class="btn btn-secondary btn-sm">Edit</button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="two-col">
            <dl class="def-list">
                <dt>IP Address</dt><dd><?= h($detail_device['ip_address']) ?></dd>
                <dt>MAC Address</dt><dd class="text-mono"><?= h($detail_device['mac_address'] ?? '—') ?></dd>
                <dt>Hostname</dt><dd><?= h($detail_device['hostname'] ?? '—') ?></dd>
                <dt>Vendor</dt><dd><?= h($detail_device['vendor'] ?? '—') ?></dd>
                <dt>Device Type</dt><dd><?= h($detail_device['device_type'] ?? '—') ?></dd>
                <dt>Custom Name</dt><dd><?= h($detail_device['custom_name'] ?? '—') ?></dd>
                <dt>First Seen</dt><dd><?= h($detail_device['first_seen'] ?? '—') ?></dd>
                <dt>Last Seen</dt><dd><?= h($detail_device['last_seen'] ?? '—') ?></dd>
                <dt>Last Scanned</dt><dd><?= h($detail_device['last_scan'] ?? '—') ?></dd>
            </dl>

            <div>
                <h3 class="section-title">Open Ports</h3>
                <?php
                $ports = json_decode($detail_device['open_ports'] ?? '[]', true);
                if (!empty($ports)):
                ?>
                <table class="table table--compact">
                    <thead><tr><th>Port</th><th>Proto</th><th>Service</th><th>Product</th></tr></thead>
                    <tbody>
                        <?php foreach ($ports as $p): ?>
                        <tr>
                            <td class="text-mono"><?= h($p['port']) ?></td>
                            <td><?= h($p['proto']) ?></td>
                            <td><?= h($p['service'] ?? '') ?></td>
                            <td class="text-sm text-muted"><?= h($p['product'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-muted">No open ports detected.</p>
                <?php endif; ?>

                <?php if ($detail_device['notes']): ?>
                <h3 class="section-title" style="margin-top:1rem;">Notes</h3>
                <p><?= nl2br(h($detail_device['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Edit form -->
        <?php if (is_admin()): ?>
        <div id="editForm" class="hidden" style="margin-top:1.5rem; padding-top:1.5rem; border-top:1px solid var(--border);">
            <form method="POST" action="/devices.php">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="action" value="save_device">
                <input type="hidden" name="device_id" value="<?= h($detail_device['id']) ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Custom Name</label>
                        <input type="text" name="custom_name" class="form-control"
                            value="<?= h($detail_device['custom_name'] ?? '') ?>" placeholder="e.g. Office Printer">
                    </div>
                    <div class="form-group">
                        <label>Device Type</label>
                        <select name="device_type" class="form-control">
                            <option value="">— Select —</option>
                            <?php foreach (['Router','Switch','Access Point','Server','Workstation','Laptop','Printer','IoT Device','IP Camera','NAS','Other'] as $t): ?>
                            <option value="<?= h($t) ?>" <?= $detail_device['device_type'] === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" class="form-control" rows="3"><?= h($detail_device['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
