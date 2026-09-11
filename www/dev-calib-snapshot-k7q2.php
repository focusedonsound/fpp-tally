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
// Captures via ffmpeg + V4L2 for a generic USB webcam, or rpicam-still for
// a CSI camera (Pi Camera Module) -- auto-detected, see calib_detect_csi()
// below. Verified directly against a real Pi Camera Module 3: ffmpeg's
// v4l2 path "succeeds" against a CSI sensor's raw device node but encodes
// its unprocessed Bayer data as if it were normal color data, producing a
// solid green-tinted image rather than a real photo. One JPEG per
// request, not a continuous stream, so the capture device isn't held open
// between the calibration page's ~1.5s polls.
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

// See diag_snapshot.php's identical function for the full explanation --
// a CSI camera's raw /dev/videoN node needs rpicam-still, not ffmpeg
// -f v4l2 (which "succeeds" against it but produces a garbage green-cast
// image, not a real photo or a clean error). Cached to the same file so
// both routes share one detection result instead of running it twice.
function calib_detect_csi($cacheFile) {
    $cached = @json_decode(@file_get_contents($cacheFile), true);
    if (is_array($cached) && isset($cached['is_csi']) && (time() - ($cached['at'] ?? 0)) < 60) {
        return (bool)$cached['is_csi'];
    }
    $helloBin = trim((string)(shell_exec('command -v rpicam-hello 2>/dev/null') ?: shell_exec('command -v libcamera-hello 2>/dev/null')));
    $isCsi = false;
    if ($helloBin !== '') {
        $out = (string)shell_exec('timeout 5 ' . escapeshellarg($helloBin) . ' --list-cameras 2>&1');
        $isCsi = (strpos($out, 'Available cameras') !== false && strpos($out, 'No cameras available') === false);
    }
    @file_put_contents($cacheFile, json_encode(['is_csi' => $isCsi, 'at' => time()]));
    return $isCsi;
}

if (!calib_session_active($SESSION_FILE)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'calibration session not active';
    exit;
}

$cfg = calib_load_cfg($CONFIG_FILE);

// Same reasoning as diag_snapshot.php's identical block: the camera
// module, when enabled, holds this device open continuously, so serve its
// shared warm frame instead of racing it for a second exclusive open.
$FRAME_FILE = "/home/fpp/media/plugins/fpp-tally/state/camera_frame.jpg";
$FRAME_FILE_MAX_AGE_S = 5.0;
if (!empty($cfg['modules']['camera']) && file_exists($FRAME_FILE)) {
    $age = time() - filemtime($FRAME_FILE);
    if ($age <= $FRAME_FILE_MAX_AGE_S) {
        $jpeg = @file_get_contents($FRAME_FILE);
        if ($jpeg !== false && strlen($jpeg) >= 4 && substr($jpeg, 0, 2) === "\xFF\xD8") {
            header('Content-Type: image/jpeg');
            header('Cache-Control: no-store');
            echo $jpeg;
            exit;
        }
    }
}

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
$csiCacheFile = "/home/fpp/media/plugins/fpp-tally/state/camera_backend_detect.json";
if (calib_detect_csi($csiCacheFile)) {
    $stillBin = trim((string)(shell_exec('command -v rpicam-still 2>/dev/null') ?: shell_exec('command -v libcamera-still 2>/dev/null')));
    $cmd = 'timeout 5 ' . escapeshellarg($stillBin ?: 'rpicam-still') . ' -t 300 -o - 2>/dev/null';
} else {
    $cmd = 'timeout 5 ffmpeg -f v4l2 -i ' . escapeshellarg($device) .
           ' -frames:v 1 -q:v 5 -f mjpeg -y - 2>/dev/null';
}
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
