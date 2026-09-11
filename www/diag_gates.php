<?php
// diag_gates.php — Diagnostics page's per-gate sensitivity read/write.
//
// The radar's own native per-gate sensitivity (the same feature the
// official HLK config tool exposes -- see ld2410_protocol.py's
// ld2410_read_gate_config/ld2410_set_gate_sensitivity) can only be
// read/written through the serial connection the daemon's ld2410.py
// module already has open. This endpoint can't touch that connection
// directly (a second open would conflict with it), so it drops a
// request file and waits briefly for the daemon's own run loop to
// service it and write a response -- see GATE_CMD_FILE/GATE_RESULT_FILE
// in ld2410.py.
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

$STATE_DIR    = "/home/fpp/media/plugins/fpp-tally/state";
$CMD_FILE     = "$STATE_DIR/ld2410_gate_cmd.json";
$RESULT_FILE  = "$STATE_DIR/ld2410_gate_result.json";

$action = trim($_POST['action'] ?? '');
$side = trim($_POST['side'] ?? '');
if (!in_array($action, ['read', 'write'], true)) {
    respond(false, 'Unknown action');
}
if (!in_array($side, ['A', 'B'], true)) {
    respond(false, 'side must be A or B');
}

$req = ['action' => $action, 'side' => $side];

if ($action === 'write') {
    // gate: a specific 0-8 index, or "all" to set every gate at once
    // (0xFFFF sentinel per the protocol -- see ld2410_protocol.py).
    $gateParam = trim($_POST['gate'] ?? '');
    if ($gateParam === 'all') {
        $req['gate'] = 0xFFFF;
    } elseif (is_numeric($gateParam) && (int)$gateParam >= 0 && (int)$gateParam <= 8) {
        $req['gate'] = (int)$gateParam;
    } else {
        respond(false, 'gate must be 0-8 or "all"');
    }

    $motion = $_POST['motion'] ?? null;
    $static = $_POST['static'] ?? null;
    if (!is_numeric($motion) || !is_numeric($static)) {
        respond(false, 'motion and static sensitivity must be numbers');
    }
    $motion = (int)$motion;
    $static = (int)$static;
    if ($motion < 0 || $motion > 100 || $static < 0 || $static > 100) {
        respond(false, 'sensitivity must be 0-100');
    }
    $req['motion'] = $motion;
    $req['static'] = $static;
}

@mkdir($STATE_DIR, 0755, true);
// Clear any stale result before asking for a fresh one, so we can tell
// a genuinely-new response apart from a leftover old one.
@unlink($RESULT_FILE);
if (@file_put_contents($CMD_FILE, json_encode($req)) === false) {
    respond(false, 'Could not write request — is the daemon installed and the ld2410 module enabled?');
}

// Wait for the daemon's own run loop to pick this up and answer --
// checked every loop tick there, so this is normally near-instant, but
// a config-mode round trip (with retries) can take a couple of seconds.
$deadline = microtime(true) + 5.0;
while (microtime(true) < $deadline) {
    if (file_exists($RESULT_FILE)) {
        $raw = @file_get_contents($RESULT_FILE);
        $result = @json_decode($raw, true);
        if (is_array($result)) {
            respond(!empty($result['ok']), $result['message'] ?? '', $result);
        }
    }
    usleep(150000); // 150ms
}

respond(false, 'Timed out waiting for the daemon — is the ld2410 module running for this side?');
