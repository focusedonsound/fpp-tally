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
//
// $maxAgeS is only the fallback for writers with no scan cadence of
// their own (ld2410's 0.5s heartbeat). BLE/WiFi only write once per
// interval_s, so a fixed 5s threshold would show "Stale" for most of
// the idle time between two perfectly good scans -- confirmed on real
// hardware, where a 30s scan interval sat "stale" 25+ of every 30
// seconds. When the payload carries its own interval_s, judge staleness
// against that instead, with slack for the scan window itself plus one
// missed cycle before calling it stale.
function withStale($data, $maxAgeS = 5.0) {
    if ($data === null) return null;
    $updatedAt = $data['updated_at'] ?? 0;
    $effectiveMaxAge = isset($data['interval_s']) ? ((float)$data['interval_s'] * 2.5) : $maxAgeS;
    $data['stale'] = (microtime(true) - (float)$updatedAt) > $effectiveMaxAge;
    return $data;
}

$cfg = [];
if (file_exists($CONFIG_FILE)) {
    $j = @json_decode(file_get_contents($CONFIG_FILE), true);
    if (is_array($j)) $cfg = $j;
}
$modules = $cfg['modules'] ?? [];
$cs      = $cfg['crowd_scan'] ?? [];
$calib   = $cfg['calibration'] ?? [];

$result = [
    "modules"        => [
        "ld2410"     => !empty($modules['ld2410']),
        "crowd_ble"  => !empty($modules['crowd_ble']),
        "crowd_wifi" => !empty($modules['crowd_wifi']),
        "thermal"    => !empty($modules['thermal']),
        "camera"     => !empty($modules['camera']),
    ],
    "wifi_interface" => $cs['wifi_interface'] ?? 'wlan0',
    "ld2410_min_energy" => (int)($cfg['ld2410']['min_energy'] ?? 20),
    // Current tunable thermal params, for the Diagnostics page's Thermal
    // Tuning card to pre-populate its inputs from -- same idea as
    // ld2410_min_energy above, just multiple fields at once since thermal
    // has several independent knobs instead of one.
    "thermal_config" => [
        "delta_threshold_c" => $cfg['thermal']['delta_threshold_c'] ?? 2.0,
        "min_blob_size"     => (int)($cfg['thermal']['min_blob_size'] ?? 6),
        "min_travel_cols"   => $cfg['thermal']['min_travel_cols'] ?? 4,
        "parked_timeout_s"  => (int)($cfg['thermal']['parked_timeout_s'] ?? 180),
        "mount_distance_m"  => $cfg['thermal']['mount_distance_m'] ?? 5.0,
        "flip_direction"    => !empty($cfg['thermal']['flip_direction']),
    ],
    "camera"         => [
        "require_password" => !empty($calib['require_password_on_diagnostics']),
        // Auto-tuning-assist classifier's live readout -- see camera.py.
        // Distinct from the require_password diagnostics-snapshot flag
        // above; both live under "camera" since they're both camera
        // related, but they're otherwise unconnected features.
        "classify" => withStale(readJsonFile("$STATE_DIR/camera_live.json"), 10.0),
        "awb_mode"  => $cfg['camera']['awb_mode'] ?? 'auto',
        "awb_gains" => $cfg['camera']['awb_gains'] ?? '',
        // White balance only does anything on the CSI/rpicam path (see
        // camera.py) -- surfaced here so the Diagnostics page can tell
        // the user their WB setting is a no-op on a USB webcam instead
        // of silently doing nothing. Same detection cache diag_snapshot.php
        // writes/reads; a missing/expired cache just reads as unknown
        // (null) rather than guessing.
        "is_csi"    => (function () {
            $c = @json_decode(@file_get_contents("/home/fpp/media/plugins/fpp-tally/state/camera_backend_detect.json"), true);
            return (is_array($c) && isset($c['is_csi'])) ? (bool)$c['is_csi'] : null;
        })(),
    ],
    "ld2410"         => withStale(readJsonFile("$STATE_DIR/ld2410_live.json")),
    "crowd_ble"      => withStale(readJsonFile("$STATE_DIR/crowd_ble_live.json")),
    "crowd_wifi"     => withStale(readJsonFile("$STATE_DIR/crowd_wifi_live.json")),
    "thermal"        => withStale(readJsonFile("$STATE_DIR/thermal_live.json"), 3.0),
];

echo json_encode($result);
