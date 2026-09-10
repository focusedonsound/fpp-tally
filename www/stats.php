<?php
// stats.php — Tally Reporting page range-query data source.
// GET days=7|30|90|365 -- everything else (zones, hourly distribution,
// crowd estimate, environment) is derived from that one range, matching
// the project spec section 11 (7/30/90/365-day views, hourly-of-day
// distribution, crowd/environment charts alongside traffic).
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$CONFIG_FILE = "/home/fpp/media/config/tally.json";
$DB_PATH     = "/home/fpp/media/plugins/fpp-tally/state/tally.db";

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90, 365], true)) $days = 30;

$cfg = [];
if (file_exists($CONFIG_FILE)) {
    $j = @json_decode(file_get_contents($CONFIG_FILE), true);
    if (is_array($j)) $cfg = $j;
}
$modules = $cfg['modules'] ?? [];
$zones   = $cfg['zones'] ?? [];

$result = ["days" => $days, "zones" => [], "hourly" => [], "crowd" => null, "environment" => null];

if (!file_exists($DB_PATH)) {
    echo json_encode($result);
    exit;
}

// Pre-fill every date in the range so a day with zero events still shows
// as a zero bar rather than a gap the chart would otherwise interpolate
// across.
function dateRange($days) {
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $out[] = date('Y-m-d', strtotime("-{$i} days"));
    }
    return $out;
}

try {
    $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);
    $labels = dateRange($days);

    foreach ($zones as $zoneKey => $zoneCfg) {
        $moduleName = $zoneCfg['module'] ?? '';
        if ($moduleName === '' || empty($modules[$moduleName])) continue;

        $labelA = $zoneCfg['direction_a_label'] ?? 'Inbound';
        $labelB = $zoneCfg['direction_b_label'] ?? 'Outbound';

        $byDate = [];
        foreach ($labels as $d) $byDate[$d] = ['a' => 0, 'b' => 0, 'parked' => 0];

        $stmt = $db->prepare(
            "SELECT substr(timestamp,1,10) AS d, event_type, direction, COUNT(*) AS n
             FROM events WHERE zone = :z AND timestamp >= datetime('now', :range)
             GROUP BY d, event_type, direction"
        );
        $stmt->bindValue(':z', $zoneKey, SQLITE3_TEXT);
        $stmt->bindValue(':range', "-{$days} days", SQLITE3_TEXT);
        $rs = $stmt->execute();
        while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
            if (!isset($byDate[$row['d']])) continue; // outside the pre-filled range (clock skew edge case)
            if ($row['event_type'] === 'parked') {
                $byDate[$row['d']]['parked'] += (int)$row['n'];
            } elseif ($row['direction'] === $labelA) {
                $byDate[$row['d']]['a'] += (int)$row['n'];
            } elseif ($row['direction'] === $labelB) {
                $byDate[$row['d']]['b'] += (int)$row['n'];
            }
        }

        $result['zones'][$zoneKey] = [
            'label'   => $zoneCfg['label'] ?? $zoneKey,
            'label_a' => $labelA,
            'label_b' => $labelB,
            'chart'   => [
                'labels'      => $labels,
                'direction_a' => array_map(fn($d) => $byDate[$d]['a'], $labels),
                'direction_b' => array_map(fn($d) => $byDate[$d]['b'], $labels),
                'parked'      => array_map(fn($d) => $byDate[$d]['parked'], $labels),
            ],
        ];
    }

    // Hourly-of-day distribution, combined across every enabled zone --
    // the spec describes one chart ("Bar chart of vehicle traffic by
    // hour-of-day"), not one per zone.
    $hourly = [];
    for ($h = 0; $h < 24; $h++) $hourly[sprintf('%02d', $h)] = 0;
    if (!empty($result['zones'])) {
        $stmt = $db->prepare(
            "SELECT strftime('%H', timestamp, 'localtime') AS hr, COUNT(*) AS n FROM events
             WHERE event_type = 'pass' AND timestamp >= datetime('now', :range) GROUP BY hr"
        );
        $stmt->bindValue(':range', "-{$days} days", SQLITE3_TEXT);
        $rs = $stmt->execute();
        while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
            if ($row['hr'] !== null) $hourly[$row['hr']] = (int)$row['n'];
        }
    }
    $result['hourly'] = $hourly;

    // Crowd/device estimate over time (if BLE and/or WiFi enabled).
    if (!empty($modules['crowd_ble']) || !empty($modules['crowd_wifi'])) {
        $stmt = $db->prepare(
            "SELECT timestamp, adjusted_count FROM device_scans
             WHERE timestamp >= datetime('now', :range) ORDER BY id ASC"
        );
        $stmt->bindValue(':range', "-{$days} days", SQLITE3_TEXT);
        $rs = $stmt->execute();
        $ts = []; $vals = [];
        while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
            $ts[] = $row['timestamp'];
            $vals[] = (int)$row['adjusted_count'];
        }
        $result['crowd'] = ['labels' => $ts, 'data' => $vals];
    }

    // Environment (temperature/humidity) over time, if BME280 or DHT11 is
    // enabled -- both log to the same environment table (see
    // tally_daemon.py's _handle_environment_event()), shown alongside the
    // traffic charts for correlation, per spec.
    if (!empty($modules['bme280']) || !empty($modules['dht11'])) {
        $stmt = $db->prepare(
            "SELECT timestamp, temperature_f, humidity_pct FROM environment
             WHERE timestamp >= datetime('now', :range) ORDER BY id ASC"
        );
        $stmt->bindValue(':range', "-{$days} days", SQLITE3_TEXT);
        $rs = $stmt->execute();
        $ts = []; $temps = []; $hums = [];
        while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
            $ts[] = $row['timestamp'];
            $temps[] = $row['temperature_f'] !== null ? round((float)$row['temperature_f'], 1) : null;
            $hums[] = $row['humidity_pct'] !== null ? round((float)$row['humidity_pct'], 1) : null;
        }
        $result['environment'] = ['labels' => $ts, 'temperature_f' => $temps, 'humidity_pct' => $hums];
    }

    $db->close();
    echo json_encode($result);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
