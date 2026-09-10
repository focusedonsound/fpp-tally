<?php
// trigger.php — Tally Setup page diagnostics buttons.
// Same command-queue mechanism the FPP Command wrapper scripts use
// (commands/tally_cmd_test_vehicle.sh / tally_cmd_test_crowd.sh) — this
// endpoint just lets the Setup page's Diagnostics section fire the same
// simulated events without going through FPP's command dispatcher.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($ok, $msg) {
    echo json_encode(["status" => $ok ? "OK" : "ERROR", "message" => $msg]);
    exit;
}

$CMD_QUEUE = "/home/fpp/media/plugins/fpp-tally/state/tally_trigger.cmd";
@mkdir(dirname($CMD_QUEUE), 0755, true);

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'vehicle') {
    $zone = trim($_POST['zone'] ?? $_GET['zone'] ?? 'driveway');
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $zone)) respond(false, 'Invalid zone name');
    if (@file_put_contents($CMD_QUEUE, "trigger_vehicle:{$zone}") === false) {
        respond(false, 'Could not write command queue — is the daemon installed?');
    }
    respond(true, "Simulated vehicle event queued for zone '{$zone}'.");
} elseif ($action === 'crowd') {
    if (@file_put_contents($CMD_QUEUE, "trigger_crowd") === false) {
        respond(false, 'Could not write command queue — is the daemon installed?');
    }
    respond(true, "Simulated crowd scan queued.");
} else {
    respond(false, 'Unknown action');
}
