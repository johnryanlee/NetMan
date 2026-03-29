<?php
// Utility functions

/**
 * Detect LAN network ranges from the probe's network interfaces.
 * Returns an array of ['interface', 'ip', 'cidr', 'network'] entries.
 */
function detect_network_ranges(): array {
    $ranges = [];

    // Use `ip -4 addr show` to list interfaces
    $output = [];
    exec('ip -4 addr show 2>/dev/null', $output);

    $current_iface = null;
    foreach ($output as $line) {
        $line = trim($line);

        // Interface line: "2: eth0: <BROADCAST,..."
        if (preg_match('/^\d+:\s+(\S+):/', $line, $m)) {
            $current_iface = $m[1];
        }

        // IP line: "inet 192.168.1.100/24 brd ..."
        if ($current_iface && preg_match('/^inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
            $ip   = $m[1];
            $bits = (int)$m[2];

            // Skip loopback and link-local
            if (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) continue;

            $network = long2ip(ip2long($ip) & (~((1 << (32 - $bits)) - 1)));
            $cidr    = "$network/$bits";

            $ranges[] = [
                'interface' => $current_iface,
                'ip'        => $ip,
                'cidr'      => $cidr,
                'network'   => $network,
                'bits'      => $bits,
            ];
        }
    }

    return $ranges;
}

/**
 * Parse nmap XML output and return array of discovered hosts.
 */
function parse_nmap_xml(string $xml_file): array {
    if (!file_exists($xml_file)) return [];

    $xml = simplexml_load_file($xml_file);
    if (!$xml) return [];

    $hosts = [];
    foreach ($xml->host as $host) {
        // Only include hosts that are up
        $status = (string)($host->status['state'] ?? '');
        if ($status !== 'up') continue;

        $entry = [
            'ip_address'  => '',
            'mac_address' => null,
            'hostname'    => null,
            'vendor'      => null,
            'open_ports'  => [],
        ];

        // IP and MAC addresses
        foreach ($host->address as $addr) {
            $type = (string)$addr['addrtype'];
            $val  = (string)$addr['addr'];
            if ($type === 'ipv4') {
                $entry['ip_address'] = $val;
            } elseif ($type === 'mac') {
                $entry['mac_address'] = strtoupper($val);
                $entry['vendor']      = (string)($addr['vendor'] ?? null) ?: null;
            }
        }

        if (empty($entry['ip_address'])) continue;

        // Hostname
        foreach ($host->hostnames->hostname ?? [] as $hn) {
            if ((string)$hn['type'] === 'PTR' || (string)$hn['type'] === 'user') {
                $entry['hostname'] = (string)$hn['name'];
                break;
            }
        }

        // Open ports
        foreach ($host->ports->port ?? [] as $port) {
            if ((string)$port->state['state'] === 'open') {
                $entry['open_ports'][] = [
                    'port'     => (int)$port['portid'],
                    'proto'    => (string)$port['protocol'],
                    'service'  => (string)($port->service['name'] ?? ''),
                    'product'  => (string)($port->service['product'] ?? ''),
                ];
            }
        }

        $hosts[] = $entry;
    }

    return $hosts;
}

/**
 * Upsert a discovered device into the database.
 * Returns ['new' => bool, 'id' => int].
 */
function upsert_device(array $host): array {
    require_once __DIR__ . '/db.php';

    $ip    = $host['ip_address'];
    $mac   = $host['mac_address'];
    $ports = !empty($host['open_ports']) ? json_encode($host['open_ports']) : null;

    $existing = db_get('SELECT id FROM devices WHERE ip_address = ?', [$ip]);

    if ($existing) {
        db_exec(
            'UPDATE devices SET
                mac_address  = COALESCE(?, mac_address),
                hostname     = COALESCE(?, hostname),
                vendor       = COALESCE(?, vendor),
                open_ports   = ?,
                status       = "online",
                last_seen    = NOW(),
                last_scan    = NOW()
             WHERE ip_address = ?',
            [$mac, $host['hostname'], $host['vendor'], $ports, $ip]
        );
        return ['new' => false, 'id' => (int)$existing['id']];
    }

    $id = db_run(
        'INSERT INTO devices
            (ip_address, mac_address, hostname, vendor, open_ports, status, first_seen, last_seen, last_scan)
         VALUES (?, ?, ?, ?, ?, "online", NOW(), NOW(), NOW())',
        [$ip, $mac, $host['hostname'], $host['vendor'], $ports]
    );
    return ['new' => true, 'id' => $id];
}

/**
 * Mark all devices in a range as offline if not seen in this scan.
 */
function mark_offline_devices(string $range, \DateTime $scan_started): void {
    require_once __DIR__ . '/db.php';
    // Devices in the subnet that weren't updated during this scan are now offline
    db_exec(
        'UPDATE devices SET status = "offline"
         WHERE last_seen < ? AND last_scan < ?',
        [
            $scan_started->format('Y-m-d H:i:s'),
            $scan_started->format('Y-m-d H:i:s'),
        ]
    );
}

/**
 * Sanitize output for safe HTML display.
 */
function h(mixed $val): string {
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Return a human-readable "time ago" string.
 */
function time_ago(?string $datetime): string {
    if (!$datetime) return 'Never';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

/**
 * Return JSON response and exit.
 */
function json_response(mixed $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Validate CIDR notation.
 */
function valid_cidr(string $cidr): bool {
    if (!preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/', $cidr)) return false;
    [$ip, $bits] = explode('/', $cidr);
    $parts = explode('.', $ip);
    foreach ($parts as $p) {
        if ((int)$p > 255) return false;
    }
    return (int)$bits >= 8 && (int)$bits <= 32;
}
