<?php
/**
 * NetMan Installation Wizard
 * Handles first-time setup: DB verification, network detection, admin account creation.
 */

session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);

$config_file = __DIR__ . '/config.php';
$step = (int)($_GET['step'] ?? 1);
$error = '';
$success = '';

// If already installed and configured, redirect to login
if (file_exists($config_file)) {
    require_once $config_file;
    if (defined('APP_INSTALLED') && APP_INSTALLED === true) {
        header('Location: /login.php');
        exit;
    }
}

// ─── Step handlers ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!hash_equals($_SESSION['install_token'] ?? '', $_POST['install_token'] ?? '')) {
        $error = 'Invalid request token. Please try again.';
        $step = (int)($_POST['step'] ?? 1);
    } else {

        // Step 1: Test DB connection + import schema
        if ($_POST['step'] == '1') {
            $db_host = trim($_POST['db_host'] ?? 'localhost');
            $db_user = trim($_POST['db_user'] ?? '');
            $db_pass = $_POST['db_pass'] ?? '';
            $db_name = trim($_POST['db_name'] ?? 'netman');

            try {
                $pdo = new PDO(
                    "mysql:host=$db_host;charset=utf8mb4",
                    $db_user, $db_pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                // Create DB if not exists
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `$db_name`");

                // Import schema
                $schema = file_get_contents(__DIR__ . '/schema.sql');
                // Strip USE statement (already selected)
                $schema = preg_replace('/^USE\s+\w+;\s*/mi', '', $schema);
                // Split on semicolons and execute each statement
                foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
                    if (!empty($stmt)) $pdo->exec($stmt);
                }

                // Store in session for next steps
                $_SESSION['install_db'] = compact('db_host', 'db_user', 'db_pass', 'db_name');
                $step = 2;

            } catch (PDOException $e) {
                $error = 'Database connection failed: ' . $e->getMessage();
                $step = 1;
            }
        }

        // Step 2: Network detection — user confirms the scan range
        elseif ($_POST['step'] == '2') {
            require_once __DIR__ . '/includes/functions.php';
            $scan_range = trim($_POST['scan_range'] ?? '');

            if (!valid_cidr($scan_range)) {
                $error = 'Invalid network range. Please use CIDR format, e.g. 192.168.1.0/24';
                $step = 2;
            } else {
                $_SESSION['install_range'] = $scan_range;
                $step = 3;
            }
        }

        // Step 3: Create admin account
        elseif ($_POST['step'] == '3') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $password2 = $_POST['password2'] ?? '';

            if (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                $error = 'Username must be at least 3 characters (letters, numbers, underscores only).';
                $step = 3;
            } elseif (strlen($password) < 8) {
                $error = 'Password must be at least 8 characters.';
                $step = 3;
            } elseif ($password !== $password2) {
                $error = 'Passwords do not match.';
                $step = 3;
            } else {
                try {
                    $db = $_SESSION['install_db'] ?? [];
                    $pdo = new PDO(
                        "mysql:host={$db['db_host']};dbname={$db['db_name']};charset=utf8mb4",
                        $db['db_user'], $db['db_pass'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );

                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, "admin")')
                        ->execute([$username, $hash]);

                    // Save scan range setting
                    $range = $_SESSION['install_range'] ?? '';
                    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('scan_range', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                        ->execute([$range]);

                    // Write config.php
                    $secret = bin2hex(random_bytes(32));
                    $config_content = "<?php\n" .
                        "define('DB_HOST', " . var_export($db['db_host'], true) . ");\n" .
                        "define('DB_USER', " . var_export($db['db_user'], true) . ");\n" .
                        "define('DB_PASS', " . var_export($db['db_pass'], true) . ");\n" .
                        "define('DB_NAME', " . var_export($db['db_name'], true) . ");\n" .
                        "define('APP_SECRET', " . var_export($secret, true) . ");\n" .
                        "define('APP_INSTALLED', true);\n";

                    file_put_contents($config_file, $config_content);
                    chmod($config_file, 0640);

                    $step = 4; // Complete
                    unset($_SESSION['install_db'], $_SESSION['install_range']);

                } catch (PDOException $e) {
                    $error = 'Failed to create admin account: ' . $e->getMessage();
                    $step = 3;
                }
            }
        }
    }
}

// Generate install token for CSRF
if (empty($_SESSION['install_token'])) {
    $_SESSION['install_token'] = bin2hex(random_bytes(32));
}

// Auto-detect network ranges for step 2
$detected_ranges = [];
if ($step == 2) {
    require_once __DIR__ . '/includes/functions.php';
    $detected_ranges = detect_network_ranges();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetMan Setup Wizard</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="install-body">

<div class="install-container">
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

    <!-- Progress steps -->
    <div class="install-steps">
        <?php foreach (['Database', 'Network', 'Admin', 'Done'] as $i => $label): ?>
        <div class="install-step <?= $step > $i+1 ? 'done' : ($step === $i+1 ? 'active' : '') ?>">
            <div class="step-dot"><?= $step > $i+1 ? '✓' : ($i+1) ?></div>
            <span><?= $label ?></span>
        </div>
        <?php if ($i < 3): ?><div class="step-connector"></div><?php endif; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ── Step 1: Database ─────────────────────────────────── -->
    <?php if ($step === 1): ?>
    <div class="install-card">
        <h2>Database Connection</h2>
        <p class="text-muted">Enter your MySQL database credentials. The database and tables will be created automatically.</p>

        <form method="POST" action="/install.php?step=1">
            <input type="hidden" name="install_token" value="<?= htmlspecialchars($_SESSION['install_token']) ?>">
            <input type="hidden" name="step" value="1">

            <div class="form-group">
                <label>Database Host</label>
                <input type="text" name="db_host" value="localhost" required class="form-control" placeholder="localhost">
            </div>
            <div class="form-group">
                <label>Database Name</label>
                <input type="text" name="db_name" value="netman" required class="form-control" placeholder="netman">
            </div>
            <div class="form-group">
                <label>Database User</label>
                <input type="text" name="db_user" value="netman" required class="form-control" placeholder="netman">
            </div>
            <div class="form-group">
                <label>Database Password</label>
                <input type="password" name="db_pass" class="form-control" placeholder="Password">
            </div>
            <button type="submit" class="btn btn-primary btn-full">Test Connection &amp; Continue &rarr;</button>
        </form>
    </div>

    <!-- ── Step 2: Network Range ────────────────────────────── -->
    <?php elseif ($step === 2): ?>
    <div class="install-card">
        <h2>Network Range</h2>
        <p class="text-muted">NetMan detected the following network interfaces on this probe. Select the LAN range to scan or enter a custom CIDR range.</p>

        <form method="POST" action="/install.php?step=2">
            <input type="hidden" name="install_token" value="<?= htmlspecialchars($_SESSION['install_token']) ?>">
            <input type="hidden" name="step" value="2">

            <?php if (!empty($detected_ranges)): ?>
            <div class="detected-ranges">
                <label>Detected Interfaces</label>
                <?php foreach ($detected_ranges as $r): ?>
                <label class="range-option">
                    <input type="radio" name="scan_range" value="<?= htmlspecialchars($r['cidr']) ?>"
                        onclick="document.getElementById('custom_range').value=this.value">
                    <div class="range-info">
                        <span class="range-iface"><?= htmlspecialchars($r['interface']) ?></span>
                        <span class="range-cidr"><?= htmlspecialchars($r['cidr']) ?></span>
                        <span class="range-ip">Probe IP: <?= htmlspecialchars($r['ip']) ?></span>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="alert alert-warning">Could not auto-detect network interfaces. Please enter the range manually.</div>
            <?php endif; ?>

            <div class="form-group" style="margin-top:1.5rem;">
                <label>Scan Range (CIDR notation)</label>
                <input type="text" id="custom_range" name="scan_range"
                    value="<?= htmlspecialchars(!empty($detected_ranges) ? $detected_ranges[0]['cidr'] : '') ?>"
                    required class="form-control" placeholder="192.168.1.0/24">
                <p class="form-help">Examples: 192.168.1.0/24 &nbsp;|&nbsp; 10.0.0.0/16 &nbsp;|&nbsp; 172.16.0.0/12</p>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Confirm Range &amp; Continue &rarr;</button>
        </form>
    </div>

    <!-- ── Step 3: Admin Account ────────────────────────────── -->
    <?php elseif ($step === 3): ?>
    <div class="install-card">
        <h2>Admin Account</h2>
        <p class="text-muted">Create the administrator account for the NetMan web interface.</p>

        <form method="POST" action="/install.php?step=3">
            <input type="hidden" name="install_token" value="<?= htmlspecialchars($_SESSION['install_token']) ?>">
            <input type="hidden" name="step" value="3">

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" value="admin" required class="form-control"
                    pattern="[a-zA-Z0-9_]+" minlength="3" placeholder="admin">
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required class="form-control"
                    minlength="8" placeholder="Min. 8 characters">
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="password2" required class="form-control" placeholder="Repeat password">
            </div>

            <div class="setup-summary">
                <p><strong>Scan Range:</strong> <?= htmlspecialchars($_SESSION['install_range'] ?? 'Not set') ?></p>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Create Account &amp; Finish &rarr;</button>
        </form>
    </div>

    <!-- ── Step 4: Complete ─────────────────────────────────── -->
    <?php elseif ($step === 4): ?>
    <div class="install-card install-complete">
        <div class="complete-icon">✓</div>
        <h2>Setup Complete!</h2>
        <p>NetMan is ready. You can now log in and run your first network scan.</p>

        <a href="/login.php" class="btn btn-primary btn-full">Go to Login &rarr;</a>
    </div>
    <?php endif; ?>

</div>

<script src="/assets/js/app.js"></script>
</body>
</html>
