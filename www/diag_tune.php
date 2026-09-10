<?php
// diag_tune.php — Diagnostics page's live-tuning endpoint.
//
// Patches a single field (ld2410.min_energy) into the existing config
// rather than reusing save.php's full-form rebuild -- save.php treats a
// missing POST field as "unset," so posting anything less than the
// entire Setup form to it would silently blank out every other setting.
// This endpoint reads the config, changes exactly one value, and writes
// the whole thing back untouched otherwise.
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

if ($action !== 'set_min_energy') {
    respond(false, 'Unknown action');
}

$value = $_POST['min_energy'] ?? null;
if (!is_numeric($value)) {
    respond(false, 'min_energy must be a number');
}
$value = (int)$value;
if ($value < 0 || $value > 500) {
    respond(false, 'min_energy out of range (0-500)');
}

if (!file_exists($CONFIG_FILE)) {
    respond(false, 'Config file not found');
}
$cfg = json_decode(file_get_contents($CONFIG_FILE), true);
if (!is_array($cfg)) {
    respond(false, 'Config file unreadable');
}

$cfg['ld2410'] = $cfg['ld2410'] ?? [];
$cfg['ld2410']['min_energy'] = $value;

if (@file_put_contents($CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    respond(false, 'Could not write config file');
}

// Takes effect only after the daemon restarts -- config is loaded once
// at startup (see tally_daemon.py's main()), not hot-reloaded. The
// Diagnostics page's own "Save & Apply" button chains a restart request
// to control.php right after a successful save here.
respond(true, "min_energy set to {$value}. Restart the daemon to apply it.", ["min_energy" => $value]);
