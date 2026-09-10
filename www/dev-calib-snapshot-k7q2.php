<?php
// dev-calib-snapshot-k7q2.php — single-frame camera capture for the hidden
// calibration route (dev-calib-x9f3.php). Deliberately obscure filename,
// matching that page's own "reachable only if you know the exact name"
// posture, and not linked from anywhere except that page's own JS.
//
// Re-verifies the calibration session is active on EVERY request, not just
// once at page load -- a browser tab left open past expiry (or after
// someone hits Deactivate) must stop being able to pull frames, the same
// fail-safe-off guarantee the rest of the calibration route makes.
//
// Captures via ffmpeg + V4L2 (works with a generic USB webcam, and with a
// Pi CSI camera only if the legacy V4L2 compatibility layer is enabled --
// not guaranteed for libcamera-only sensors like the Camera Module 3, a
// known limitation worth revisiting once real camera hardware is in hand).
// One JPEG per request, not a continuous stream, so the capture device
// isn't held open between the calibration page's ~1.5s polls.
ini_set('display_errors', '0');

$CONFIG_FILE  = "/home/fpp/media/config/tally.json";
$SESSION_FILE = "/home/fpp/media/plugins/fpp-tally/state/calib_session.json";
$LOG_FILE     = "/home/fpp/media/logs/plugin-fpp-tally.log";

function calib_load_cfg($path) {
    if (!file_exists($path)) return [];
    $j = @json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

function calib_session_active($sessionFile) {
    if (!file_exists($sessionFile)) return false;
    $j = @json_decode(file_get_contents($sessionFile), true);
    if (!is_array($j) || empty($j['active']) || empty($j['expires_at'])) return false;
    if ((int)$j['expires_at'] - time() <= 0) {
        @unlink($sessionFile);
        return false;
    }
    return true;
}

function calib_log($logFile, $msg) {
    @file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [calib-snapshot] {$msg}\n", FILE_APPEND | LOCK_EX);
}

if (!calib_session_active($SESSION_FILE)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'calibration session not active';
    exit;
}

$cfg = calib_load_cfg($CONFIG_FILE);
$device = $cfg['calibration']['camera_device'] ?? '/dev/video0';
if (!preg_match('#^/dev/[A-Za-z0-9_/-]+$#', $device)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'invalid camera device path';
    exit;
}

// A V4L2 device only tolerates one opener at a time. Non-blocking flock
// on the SAME lock file the Diagnostics page's camera endpoint uses
// (diag_snapshot.php) -- the two routes share one physical camera, so a
// request that loses the race here fails fast and cleanly (503) instead
// of fighting another capture already in progress, whichever route
// started it.
$lockFile = "/home/fpp/media/plugins/fpp-tally/state/camera.lock";
@mkdir(dirname($lockFile), 0755, true);
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    http_response_code(503);
    header('Content-Type: text/plain');
    header('Retry-After: 1');
    echo 'camera busy';
    exit;
}

// One frame, no temp file. 5s timeout so a device that hangs (unplugged
// mid-session, bus error) fails fast instead of piling up slow requests.
$cmd = 'timeout 5 ffmpeg -f v4l2 -i ' . escapeshellarg($device) .
       ' -frames:v 1 -q:v 5 -f mjpeg -y - 2>/dev/null';
$jpeg = shell_exec($cmd);

flock($lockFp, LOCK_UN);
fclose($lockFp);

// A real JPEG starts with the SOI marker (0xFFD8) -- cheap sanity check
// that ffmpeg actually produced image bytes and not empty/error output
// shell_exec happened to return.
if ($jpeg === null || strlen($jpeg) < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
    calib_log($LOG_FILE, "capture failed: device={$device} bytes=" . ($jpeg === null ? 'null' : strlen($jpeg)));
    http_response_code(502);
    header('Content-Type: text/plain');
    echo 'capture failed';
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: no-store');
echo $jpeg;
