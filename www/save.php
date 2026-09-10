<?php
// save.php — Tally Setup page config save endpoint
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = "/home/fpp/media/config/tally.json";

function respond($ok, $msg) {
    echo json_encode(["status" => $ok ? "OK" : "ERROR", "message" => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'POST required');
}

$dir = dirname($configFile);
if (!is_dir($dir))      respond(false, "Config directory missing: $dir");
if (!is_writable($dir)) respond(false, "Config directory not writable");

$cfg = [];
if (file_exists($configFile)) {
    $j = @json_decode(file_get_contents($configFile), true);
    if (is_array($j)) $cfg = $j;
}

function b($v) { return isset($v) && $v === "1"; }
function f($v, $d) { return is_numeric($v) ? (float)$v : $d; }
function i($v, $d) { return is_numeric($v) ? (int)$v : $d; }
function s($v, $d) { return isset($v) ? trim((string)$v) : $d; }

$cfg["enabled"] = b($_POST["enabled"] ?? null);

// ── Hardware-selection wizard ─────────────────────────────────────
$cfg["modules"] = [
    "ld2410"     => b($_POST["mod_ld2410"] ?? null),
    "thermal"    => b($_POST["mod_thermal"] ?? null),
    "crowd_ble"  => b($_POST["mod_crowd_ble"] ?? null),
    "crowd_wifi" => b($_POST["mod_crowd_wifi"] ?? null),
    "bme280"     => b($_POST["mod_bme280"] ?? null),
    "dht11"      => b($_POST["mod_dht11"] ?? null),
];

// ── Registration (soft gate on the web UI only — see tally_config.py) ────
// Calls fpp-tally-license-server's /api/register. Registration is a soft
// gate on the Setup/Reporting pages only -- the daemon never consults this
// flag (see tally_config.is_registered()'s docstring), so a network
// failure here must never block saving the rest of the form.
function tally_hwid() {
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if ($cpuinfo !== false && preg_match('/^Serial\s*:\s*([0-9a-fA-F]+)/m', $cpuinfo, $m)) {
        $serial = $m[1];
        if ($serial !== '' && $serial !== str_repeat('0', strlen($serial))) {
            return 'cpu-' . $serial;
        }
    }
    $machineId = @file_get_contents('/etc/machine-id');
    if ($machineId !== false && trim($machineId) !== '') {
        return 'machine-' . trim($machineId);
    }
    return 'unknown';
}

function tally_register($email, $licenseKey) {
    $payload = json_encode(['email' => $email, 'hwid' => tally_hwid(), 'licenseKey' => $licenseKey]);
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents('https://tally-license.nscilingo.workers.dev/api/register', false, $ctx);
    if ($resp === false) return false; // network error -- caller keeps prior state
    $data = json_decode($resp, true);
    return is_array($data) && !empty($data['registered']);
}

$regEmailInput = trim($_POST["reg_email"] ?? "");
$regLicenseKeyInput = s($_POST["reg_license_key"] ?? null, $cfg["registration"]["license_key"] ?? "");
$priorRegistered = !empty($cfg["registration"]["registered"]);

if ($regEmailInput === '') {
    // Cleared the email field -- treat as explicitly un-registering rather
    // than leaving a stale "registered: true" with no email on record.
    $registered = false;
} else {
    $serverResult = tally_register($regEmailInput, $regLicenseKeyInput);
    // A network failure (server unreachable) must not silently flip an
    // already-registered install back to unregistered -- keep the prior
    // state in that case rather than treating "couldn't reach the server"
    // the same as "the server said no."
    $registered = ($serverResult === false) ? $priorRegistered : $serverResult;
}

$cfg["registration"] = [
    "email"       => $regEmailInput,
    "license_key" => $regLicenseKeyInput,
    "registered"  => $registered,
];

// ── Zones (direction labels are free text — see project spec section 3) ──
$cfg["zones"] = [
    "entrance" => [
        "label"             => s($_POST["entrance_label"] ?? null, "Entrance"),
        "direction_a_label" => s($_POST["entrance_dir_a"] ?? null, "Inbound"),
        "direction_b_label" => s($_POST["entrance_dir_b"] ?? null, "Outbound"),
        "module"            => "thermal",
    ],
    "driveway" => [
        "label"             => s($_POST["driveway_label"] ?? null, "Driveway"),
        "direction_a_label" => s($_POST["driveway_dir_a"] ?? null, "Inbound"),
        "direction_b_label" => s($_POST["driveway_dir_b"] ?? null, "Outbound"),
        "module"            => "ld2410",
    ],
];

// ── LD2410B config ─────────────────────────────────────────────────
$cfg["ld2410"] = [
    "A" => ["port" => s($_POST["ld2410_port_a"] ?? null, "/dev/ttyUSB0")],
    "B" => ["port" => s($_POST["ld2410_port_b"] ?? null, "/dev/ttyUSB1")],
    "sequence_window_s" => f($_POST["ld2410_seq_window_s"] ?? null, 0.8),
    "parked_timeout_s"  => i($_POST["ld2410_parked_timeout_s"] ?? null, 180),
    "min_energy"        => i($_POST["ld2410_min_energy"] ?? null, 20),
    "toward_reference"  => s($_POST["ld2410_toward_reference"] ?? null, "A_to_B"),
    "zone"              => "driveway",
];

// ── Thermal config ─────────────────────────────────────────────────
$cfg["thermal"] = [
    "i2c_bus"          => i($_POST["thermal_i2c_bus"] ?? null, 1),
    "i2c_address"      => s($_POST["thermal_i2c_address"] ?? null, "0x33"),
    "frame_rate_hz"    => i($_POST["thermal_frame_rate_hz"] ?? null, 4),
    "min_blob_size"    => i($_POST["thermal_min_blob_size"] ?? null, 6),
    "delta_threshold_c"=> f($_POST["thermal_delta_threshold_c"] ?? null, 2.0),
    "min_travel_cols"  => f($_POST["thermal_min_travel_cols"] ?? null, 4),
    "flip_direction"   => b($_POST["thermal_flip_direction"] ?? null),
    "mount_height_m"   => f($_POST["thermal_mount_height_m"] ?? null, 3.0),
    "mount_angle_deg"  => f($_POST["thermal_mount_angle_deg"] ?? null, 90),
    "mount_distance_m" => f($_POST["thermal_mount_distance_m"] ?? null, 5.0),
    "parked_timeout_s" => i($_POST["thermal_parked_timeout_s"] ?? null, 180),
    "zone"             => "entrance",
];

// ── Crowd scan config ──────────────────────────────────────────────
$cfg["crowd_scan"] = [
    "ble_enabled"     => b($_POST["mod_crowd_ble"] ?? null),
    "wifi_enabled"    => b($_POST["mod_crowd_wifi"] ?? null),
    "wifi_interface"  => s($_POST["crowd_wifi_interface"] ?? null, "wlan0"),
    "interval_s"      => i($_POST["crowd_interval_s"] ?? null, 60),
    "device_offset"   => i($_POST["crowd_device_offset"] ?? null, 0),
    "crowd_threshold" => i($_POST["crowd_threshold"] ?? null, 0),
];

// ── BME280 config ──────────────────────────────────────────────────
$cfg["bme280"] = [
    "i2c_bus"         => i($_POST["bme280_i2c_bus"] ?? null, 1),
    "i2c_address"     => s($_POST["bme280_i2c_address"] ?? null, "0x76"),
    "poll_interval_s" => i($_POST["bme280_poll_interval_s"] ?? null, 600),
    "display_unit"    => (($_POST["bme280_display_unit"] ?? "F") === "C") ? "C" : "F",
];

// ── DHT11 config (not in the original spec -- added because DHT11 is the
// sensor actually available to test against; see dht11.py's docstring) ──
$cfg["dht11"] = [
    "pin"          => i($_POST["dht11_pin"] ?? null, 4),
    "interval_s"   => i($_POST["dht11_interval_s"] ?? null, 60),
    "display_unit" => (($_POST["dht11_display_unit"] ?? "F") === "C") ? "C" : "F",
];

// ── FPP Triggers ────────────────────────────────────────────────────
$triggerKeys = [
    "entrance_direction_a", "entrance_direction_b", "entrance_parked",
    "driveway_direction_a", "driveway_direction_b", "driveway_parked",
    "crowd_threshold",
];
$triggers = [];
foreach ($triggerKeys as $k) {
    $triggers[$k] = [
        "enabled"       => b($_POST["trig_{$k}_enabled"] ?? null),
        "playlist"      => s($_POST["trig_{$k}_playlist"] ?? null, ""),
        "cooldown_s"    => i($_POST["trig_{$k}_cooldown_s"] ?? null, 30),
        "play_timeout_s"=> i($_POST["trig_{$k}_play_timeout_s"] ?? null, 60),
    ];
}
$cfg["triggers"] = $triggers;

// ── MQTT ─────────────────────────────────────────────────────────────
$cfg["mqtt"] = [
    "enabled"     => b($_POST["mqtt_enabled"] ?? null),
    "base"        => s($_POST["mqtt_base"] ?? null, "tally"),
    "device_name" => s($_POST["mqtt_device_name"] ?? null, "Tally Vehicle Counter"),
];

// ── Calibration (hidden mode gating — password set separately, see
// www/dev-calib-x9f3.php; this endpoint never writes password_hash) ──────
if (!isset($cfg["calibration"])) {
    $cfg["calibration"] = ["password_hash" => "", "session_timeout_min" => 30];
}

$tmp  = $configFile . ".tmp";
$data = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents($tmp, $data) === false) respond(false, "Failed to write temp file");
if (!@rename($tmp, $configFile)) { @unlink($tmp); respond(false, "Failed to write config"); }

respond(true, "Settings saved.");
