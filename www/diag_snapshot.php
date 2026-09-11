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

// CSI (Raspberry Pi Camera Module) vs. USB webcam need different capture
// tools entirely, not just a different device path -- found on real CSI
// hardware (a Pi Camera Module 3): the raw /dev/videoN node only exposes
// unprocessed Bayer data, and `ffmpeg -f v4l2` "succeeds" against it but
// encodes that raw Bayer data as if it were normal YUV/RGB, producing a
// solid green-tinted image instead of a real photo or a clean error --
// exactly the "comes up as a green block" symptom seen on .49. This
// mirrors daemon/modules/camera.py's own backend auto-detection, cached
// to a file (detecting via `rpicam-hello --list-cameras` costs a few
// hundred ms -- too slow to redo on every ~1.5s poll from the Diagnostics
// page).
function diag_detect_csi($cacheFile) {
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

// The camera module (daemon/modules/camera.py), when enabled, keeps one
// persistent capture process open on this same device for as long as it
// runs -- a V4L2/CSI device only tolerates one opener at a time, so this
// endpoint's own cold-open ffmpeg calls started failing every single poll
// the moment that module came up (confirmed on real hardware, .51: the
// module holds the device exclusively and continuously, so every
// independent open attempt here loses the race and errors out). Rather
// than fight it for the device, serve its shared warm frame directly
// whenever it's fresh enough to trust -- effectively instant, and no
// second opener needed at all. Only falls back to the cold-open path
// below when that module isn't running (not enabled, or its frame file
// is missing/stale), preserving today's behavior for installs that don't
// use it.
$FRAME_FILE = "/home/fpp/media/plugins/fpp-tally/state/camera_frame.jpg";
$FRAME_FILE_MAX_AGE_S = 5.0;
$cameraModuleEnabled = !empty($cfg['modules']['camera']);
if ($cameraModuleEnabled && file_exists($FRAME_FILE)) {
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

$device = $calib['camera_device'] ?? '/dev/video0';
if (!preg_match('#^/dev/[A-Za-z0-9_/-]+$#', $device)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'invalid camera device path';
    exit;
}

// A V4L2 device only tolerates one opener at a time -- two overlapping
// captures (a second browser tab, this page's own poll loop racing a
// slow prior request, the hidden calibration route open at the same
// time) fighting over the same /dev/videoX is exactly what produced the
// camera panel "starting and stopping" during testing. Non-blocking
// flock: a request that loses the race fails fast and cleanly (503)
// instead of queuing behind or corrupting another capture in progress.
// The client-side poll loop (diagnostics.php's diagCamRefresh) already
// waits for each request to finish before firing the next, so this is
// defense in depth against the cases that loop can't control.
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
// mid-capture, bus error) fails fast instead of piling up slow requests.
$csiCacheFile = "/home/fpp/media/plugins/fpp-tally/state/camera_backend_detect.json";
if (diag_detect_csi($csiCacheFile)) {
    $stillBin = trim((string)(shell_exec('command -v rpicam-still 2>/dev/null') ?: shell_exec('command -v libcamera-still 2>/dev/null')));
    $cmd = 'timeout 5 ' . escapeshellarg($stillBin ?: 'rpicam-still') . ' -t 300 -o - 2>/dev/null';
} else {
    $cmd = 'timeout 5 ffmpeg -f v4l2 -i ' . escapeshellarg($device) .
           ' -frames:v 1 -q:v 5 -f mjpeg -y - 2>/dev/null';
}
$jpeg = shell_exec($cmd);

flock($lockFp, LOCK_UN);
fclose($lockFp);

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
