<?php
// diag_status.php — Diagnostics page JSON data source.
//
// Reads the ephemeral live-state files each module writes (see
// SensorModule._write_live_state() / ld2410.py's own live writer) and
// hands them straight to the frontend. Deliberately does NOT touch the
// database — this is live-only, never-persisted data (raw BLE/WiFi
// addresses are semi-sensitive; there's no reason to build a standing
// history of them just to support a live readout).
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$CONFIG_FILE = "/home/fpp/media/config/tally.json";
$STATE_DIR   = "/home/fpp/media/plugins/fpp-tally/state";

function readJsonFile($path) {
    if (!file_exists($path)) return null;
    $j = @json_decode(@file_get_contents($path), true);
    return is_array($j) ? $j : null;
}

// A live-state file is only meaningful while its writer is actively
// running -- once stale, present it as "not currently live" rather than
// as fresh data, same reasoning as ld2410.py's own stale flag.
function withStale($data, $maxAgeS = 5.0) {
    if ($data === null) return null;
    $updatedAt = $data['updated_at'] ?? 0;
    $data['stale'] = (microtime(true) - (float)$updatedAt) > $maxAgeS;
    return $data;
}

$cfg = [];
if (file_exists($CONFIG_FILE)) {
    $j = @json_decode(file_get_contents($CONFIG_FILE), true);
    if (is_array($j)) $cfg = $j;
}
$modules = $cfg['modules'] ?? [];
$cs      = $cfg['crowd_scan'] ?? [];

$result = [
    "modules"        => [
        "ld2410"     => !empty($modules['ld2410']),
        "crowd_ble"  => !empty($modules['crowd_ble']),
        "crowd_wifi" => !empty($modules['crowd_wifi']),
    ],
    "wifi_interface" => $cs['wifi_interface'] ?? 'wlan0',
    "ld2410"         => withStale(readJsonFile("$STATE_DIR/ld2410_live.json")),
    "crowd_ble"      => withStale(readJsonFile("$STATE_DIR/crowd_ble_live.json")),
    "crowd_wifi"     => withStale(readJsonFile("$STATE_DIR/crowd_wifi_live.json")),
];

echo json_encode($result);
