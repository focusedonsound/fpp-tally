<?php
// diag_tune.php — Diagnostics page's live-tuning endpoint.
//
// Patches a single field into the existing config rather than reusing
// save.php's full-form rebuild -- save.php treats a missing POST field
// as "unset," so posting anything less than the entire Setup form to it
// would silently blank out every other setting. This endpoint reads the
// config, changes exactly one value, and writes the whole thing back
// untouched otherwise.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($ok, $msg, $extra = []) {
    echo json_encode(array_merge(["status" => $ok ? "OK" : "ERROR", "message" => $msg], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'POST required');
}

$CONFIG_FILE = "/home/fpp/media/config/tally.json";
$action = trim($_POST['action'] ?? '');

if (!in_array($action, ['set_min_energy', 'set_lane_split', 'set_thermal_param', 'set_camera_wb'], true)) {
    respond(false, 'Unknown action');
}

if (!file_exists($CONFIG_FILE)) {
    respond(false, 'Config file not found');
}
$cfg = json_decode(file_get_contents($CONFIG_FILE), true);
if (!is_array($cfg)) {
    respond(false, 'Config file unreadable');
}
$cfg['ld2410'] = $cfg['ld2410'] ?? [];
$cfg['thermal'] = $cfg['thermal'] ?? [];
$cfg['camera'] = $cfg['camera'] ?? [];

$AWB_MODES = ['auto', 'incandescent', 'tungsten', 'fluorescent', 'indoor', 'daylight', 'cloudy', 'custom'];

// Whitelisted thermal fields the Diagnostics page's Thermal Tuning card
// can write, each with its own type/range -- deliberately not a generic
// "write any key" endpoint, same reasoning as min_energy/lane_split_gate
// above having their own explicit validation rather than a passthrough.
$THERMAL_FIELDS = [
    'delta_threshold_c' => ['type' => 'float', 'min' => 0.1, 'max' => 20.0],
    'min_blob_size'     => ['type' => 'int',   'min' => 1,   'max' => 200],
    'min_travel_cols'   => ['type' => 'float', 'min' => 0.5, 'max' => 32],
    'parked_timeout_s'  => ['type' => 'int',   'min' => 5,   'max' => 3600],
    'mount_distance_m'  => ['type' => 'float', 'min' => 0.3, 'max' => 50],
    'flip_direction'    => ['type' => 'bool'],
];

if ($action === 'set_min_energy') {
    $value = $_POST['min_energy'] ?? null;
    if (!is_numeric($value)) {
        respond(false, 'min_energy must be a number');
    }
    $value = (int)$value;
    if ($value < 0 || $value > 500) {
        respond(false, 'min_energy out of range (0-500)');
    }
    $cfg['ld2410']['min_energy'] = $value;
    $message = "min_energy set to {$value}. Restart the daemon to apply it.";
    $extra = ['min_energy' => $value];
} elseif ($action === 'set_lane_split') {
    // the gate index (0-8) marking the near/far lane boundary for the
    // Diagnostics page's lane view. Display-only, never read by any
    // detection/trigger logic.
    $value = $_POST['lane_split_gate'] ?? null;
    if (!is_numeric($value)) {
        respond(false, 'lane_split_gate must be a number');
    }
    $value = (int)$value;
    if ($value < 1 || $value > 8) {
        respond(false, 'lane_split_gate out of range (1-8)');
    }
    $cfg['ld2410']['lane_split_gate'] = $value;
    $message = "lane_split_gate set to {$value}. Restart the daemon to apply it.";
    $extra = ['lane_split_gate' => $value];
} elseif ($action === 'set_thermal_param') {
    $field = trim($_POST['field'] ?? '');
    if (!isset($THERMAL_FIELDS[$field])) {
        respond(false, 'Unknown thermal field');
    }
    $spec = $THERMAL_FIELDS[$field];
    $raw = $_POST['value'] ?? null;

    if ($spec['type'] === 'bool') {
        $value = in_array(strtolower((string)$raw), ['1', 'true', 'on', 'yes'], true);
    } else {
        if (!is_numeric($raw)) {
            respond(false, "$field must be a number");
        }
        $value = $spec['type'] === 'int' ? (int)$raw : (float)$raw;
        if ($value < $spec['min'] || $value > $spec['max']) {
            respond(false, "$field out of range ({$spec['min']}-{$spec['max']})");
        }
    }
    $cfg['thermal'][$field] = $value;
    $message = "$field set to " . json_encode($value) . ". Restart the daemon to apply it.";
    $extra = [$field => $value];
} else {
    // set_camera_wb -- mode and gains are written together (not as two
    // separate single-field calls like the thermal params above) because
    // they're mutually exclusive on the rpicam side: explicit gains
    // override a named mode entirely, so a stale gains value left over
    // from a previous "custom" session could silently keep overriding a
    // newly-picked named mode if this only ever touched one field at a
    // time. Posting both together means whichever the UI's dropdown
    // currently shows is exactly what gets saved -- gains empty unless
    // "custom" is actually selected.
    $mode = strtolower(trim((string)($_POST['awb_mode'] ?? 'auto')));
    if (!in_array($mode, $AWB_MODES, true)) {
        respond(false, 'Unknown awb_mode');
    }
    $gains = trim((string)($_POST['awb_gains'] ?? ''));
    if ($mode === 'custom') {
        if (!preg_match('/^\d+(\.\d+)?,\d+(\.\d+)?$/', $gains)) {
            respond(false, 'awb_gains must be "red,blue" (e.g. "1.5,1.2") when mode is custom');
        }
    } else {
        $gains = '';
    }
    $cfg['camera']['awb_mode'] = $mode;
    $cfg['camera']['awb_gains'] = $gains;
    $message = "White balance set to " . ($gains !== '' ? "gains {$gains}" : $mode) . ". Restart the daemon to apply it.";
    $extra = ['awb_mode' => $mode, 'awb_gains' => $gains];
}

if (@file_put_contents($CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    respond(false, 'Could not write config file');
}

// Takes effect only after the daemon restarts -- config is loaded once
// at startup (see tally_daemon.py's main()), not hot-reloaded. The
// Diagnostics page's own "Save & Apply" buttons chain a restart request
// to control.php right after a successful save here.
respond(true, $message, $extra);
