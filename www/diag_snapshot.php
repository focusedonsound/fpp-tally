<?php
// diag_snapshot.php — camera snapshot + password-gate endpoint for the
// Diagnostics page's camera panel.
//
// Deliberately separate from the hidden calibration route
// (dev-calib-x9f3.php / dev-calib-snapshot-k7q2.php) -- that route stays
// obscure-URL-only, untouched, and unreferenced by anything reachable
// from the (visible, Setup-linked) Diagnostics page. This endpoint is
// that route's own thing, reachable because the page it serves is
// reachable.
//
// Password gating is OFF by default (calibration.require_password_on_
// diagnostics = false) -- during development, the camera panel just
// works, same as the rest of the Diagnostics page's live-only, no-auth
// readouts. Flip that config flag on before a public-facing deployment
// and this endpoint starts requiring the SAME bcrypt password + session
// the hidden calibration route already uses (calibration.password_hash,
// state/calib_session.json) -- activated from a password prompt on the
// Diagnostics page itself via the "activate" action below. The two
// routes share that one session file by design: entering the password
// on either page unlocks camera access on both, since it's the same
// grant (camera frames), just two different doors to it.
ini_set('display_errors', '0');

$CONFIG_FILE  = "/home/fpp/media/config/tally.json";
$SESSION_FILE = "/home/fpp/media/plugins/fpp-tally/state/calib_session.json";
$LOG_FILE     = "/home/fpp/media/logs/plugin-fpp-tally.log";

function diag_load_cfg($path) {
    if (!file_exists($path)) return [];
    $j = @json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

function diag_session_active($sessionFile) {
    if (!file_exists($sessionFile)) return false;
    $j = @json_decode(file_get_contents($sessionFile), true);
    if (!is_array($j) || empty($j['active']) || empty($j['expires_at'])) return false;
    if ((int)$j['expires_at'] - time() <= 0) {
        @unlink($sessionFile);
        return false;
    }
    return true;
}

function diag_log($logFile, $msg) {
    @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [diag-snapshot] {$msg}\n", FILE_APPEND | LOCK_EX);
}

$cfg = diag_load_cfg($CONFIG_FILE);
$calib = $cfg['calibration'] ?? [];
$requirePassword = !empty($calib['require_password_on_diagnostics']);

// --- POST actions: only meaningful when password-gating is on ---------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = trim($_POST['action'] ?? '');

    if ($action === 'activate') {
        $passwordHash = $calib['password_hash'] ?? '';
        if ($passwordHash === '') {
            echo json_encode(['ok' => false, 'error' => 'No calibration password is configured for this install. See README.md.']);
            exit;
        }
        $submitted = (string)($_POST['password'] ?? '');
        if (password_verify($submitted, $passwordHash)) {
            $timeoutMin = (int)($calib['session_timeout_min'] ?? 30);
            @mkdir(dirname($SESSION_FILE), 0755, true);
            $expiresAt = time() + max(1, $timeoutMin) * 60;
            @file_put_contents($SESSION_FILE, json_encode(['active' => true, 'expires_at' => $expiresAt]));
            diag_log($LOG_FILE, "Diagnostics camera panel ACTIVATED (expires in {$timeoutMin}m)");
            echo json_encode(['ok' => true, 'expires_in_s' => $timeoutMin * 60]);
        } else {
            diag_log($LOG_FILE, "Diagnostics camera panel activation FAILED (bad password)");
            echo json_encode(['ok' => false, 'error' => 'Incorrect password.']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
}

// --- GET: single-frame capture -----------------------------------------
if ($requirePassword && !diag_session_active($SESSION_FILE)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'password required';
    exit;
}

$device = $calib['camera_device'] ?? '/dev/video0';
if (!preg_match('#^/dev/[A-Za-z0-9_/-]+$#', $device)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'invalid camera device path';
    exit;
}

// -f mjpeg to stdout: one frame, no temp file, no race between concurrent
// pollers. 5s timeout so a device that hangs fails fast instead of
// piling up slow requests.
$cmd = 'timeout 5 ffmpeg -f v4l2 -i ' . escapeshellarg($device) .
       ' -frames:v 1 -q:v 5 -f mjpeg -y - 2>/dev/null';
$jpeg = shell_exec($cmd);

if ($jpeg === null || strlen($jpeg) < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
    diag_log($LOG_FILE, "capture failed: device={$device} bytes=" . ($jpeg === null ? 'null' : strlen($jpeg)));
    http_response_code(502);
    header('Content-Type: text/plain');
    echo 'capture failed';
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: no-store');
echo $jpeg;
