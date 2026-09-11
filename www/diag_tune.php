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

if (!in_array($action, ['set_min_energy', 'set_lane_split'], true)) {
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
} else {
    // set_lane_split -- the gate index (0-8) marking the near/far lane
    // boundary for the Diagnostics page's lane view. Display-only, never
    // read by any detection/trigger logic.
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
}

if (@file_put_contents($CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    respond(false, 'Could not write config file');
}

// Takes effect only after the daemon restarts -- config is loaded once
// at startup (see tally_daemon.py's main()), not hot-reloaded. The
// Diagnostics page's own "Save & Apply" buttons chain a restart request
// to control.php right after a successful save here.
respond(true, $message, $extra);
