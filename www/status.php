<?php
// status.php — Tally Reporting page JSON data source
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$CONFIG_FILE = "/home/fpp/media/config/tally.json";
$DB_PATH     = "/home/fpp/media/plugins/fpp-tally/state/tally.db";
$PID_FILE    = "/home/fpp/media/plugins/fpp-tally/state/tally_daemon.pid";

function daemonRunning($pf) {
    if (!file_exists($pf)) return false;
    $pid = trim(@file_get_contents($pf));
    if (!$pid || !is_numeric($pid)) return false;
    if (function_exists('posix_kill')) return posix_kill((int)$pid, 0);
    return is_dir("/proc/$pid");
}

$cfg = [];
if (file_exists($CONFIG_FILE)) {
    $j = @json_decode(file_get_contents($CONFIG_FILE), true);
    if (is_array($j)) $cfg = $j;
}
$modules = $cfg['modules'] ?? [];
$zones   = $cfg['zones'] ?? [];

$result = [
    "daemon_running" => daemonRunning($PID_FILE),
    "modules"        => $modules,
    "zones"          => [],
    "crowd"          => null,
    "environment"    => null,
    "recent"         => [],
];

if (!file_exists($DB_PATH)) {
    echo json_encode($result);
    exit;
}

try {
    $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);

    $today = date('Y-m-d');

    foreach ($zones as $zoneKey => $zoneCfg) {
        $moduleName = $zoneCfg['module'] ?? '';
        if ($moduleName === '' || empty($modules[$moduleName])) continue;

        $labelA = $zoneCfg['direction_a_label'] ?? 'Inbound';
        $labelB = $zoneCfg['direction_b_label'] ?? 'Outbound';

        $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE zone=:z AND event_type='pass' AND substr(timestamp,1,10)=:d");
        $stmt->bindValue(':z', $zoneKey, SQLITE3_TEXT);
        $stmt->bindValue(':d', $today, SQLITE3_TEXT);
        $carsToday = (int)($stmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0);

        $carsTotal = (int)($db->querySingle(
            "SELECT COUNT(*) FROM events WHERE zone='" . SQLite3::escapeString($zoneKey) . "' AND event_type='pass'"
        ) ?? 0);

        $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE zone=:z AND event_type='pass' AND direction=:dir AND substr(timestamp,1,10)=:d");
        $stmt->bindValue(':z', $zoneKey, SQLITE3_TEXT);
        $stmt->bindValue(':dir', $labelA, SQLITE3_TEXT);
        $stmt->bindValue(':d', $today, SQLITE3_TEXT);
        $dirAToday = (int)($stmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE zone=:z AND event_type='pass' AND direction=:dir AND substr(timestamp,1,10)=:d");
        $stmt->bindValue(':z', $zoneKey, SQLITE3_TEXT);
        $stmt->bindValue(':dir', $labelB, SQLITE3_TEXT);
        $stmt->bindValue(':d', $today, SQLITE3_TEXT);
        $dirBToday = (int)($stmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(*) FROM events WHERE zone=:z AND event_type='parked' AND substr(timestamp,1,10)=:d");
        $stmt->bindValue(':z', $zoneKey, SQLITE3_TEXT);
        $stmt->bindValue(':d', $today, SQLITE3_TEXT);
        $parkedToday = (int)($stmt->execute()->fetchArray(SQLITE3_NUM)[0] ?? 0);

        $parkedTotal = (int)($db->querySingle(
            "SELECT COUNT(*) FROM events WHERE zone='" . SQLite3::escapeString($zoneKey) . "' AND event_type='parked'"
        ) ?? 0);

        $result["zones"][$zoneKey] = [
            "label"          => $zoneCfg['label'] ?? $zoneKey,
            "label_a"        => $labelA,
            "label_b"        => $labelB,
            "cars_today"     => $carsToday,
            "cars_total"     => $carsTotal,
            "dir_a_today"    => $dirAToday,
            "dir_b_today"    => $dirBToday,
            "parked_today"   => $parkedToday,
            "parked_total"   => $parkedTotal,
            "parked_pct"     => $carsTotal > 0 ? round(($parkedTotal / $carsTotal) * 100, 1) : 0,
        ];
    }

    if (!empty($modules['crowd_ble']) || !empty($modules['crowd_wifi'])) {
        $row = $db->querySingle(
            "SELECT adjusted_count, timestamp FROM device_scans ORDER BY id DESC LIMIT 1", true
        );
        if ($row) {
            $result["crowd"] = [
                "adjusted_count" => (int)$row['adjusted_count'],
                "timestamp"      => $row['timestamp'],
            ];
        }
    }

    if (!empty($modules['bme280'])) {
        $row = $db->querySingle(
            "SELECT temperature_f, humidity_pct, timestamp FROM environment ORDER BY id DESC LIMIT 1", true
        );
        if ($row) {
            $result["environment"] = [
                "temperature_f" => $row['temperature_f'] !== null ? round((float)$row['temperature_f'], 1) : null,
                "humidity_pct"  => $row['humidity_pct']  !== null ? round((float)$row['humidity_pct'], 1)  : null,
                "timestamp"     => $row['timestamp'],
            ];
        }
    }

    $recent = [];
    $rs = $db->query("SELECT id, timestamp, zone, sensor_source, event_type, direction, dwell_duration_s FROM events ORDER BY id DESC LIMIT 20");
    while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
        $recent[] = $row;
    }
    $result["recent"] = $recent;

    $db->close();
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
