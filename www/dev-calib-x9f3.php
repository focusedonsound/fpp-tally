<?php
// dev-calib-x9f3.php — Hidden, developer-only camera calibration route.
// See project spec section 6. This page is NEVER linked from menu.inc,
// getLinks, the Setup page, or anywhere else in this plugin — reaching it
// requires knowing this exact filename.
//
// Rules enforced here:
//  - Password is checked via password_verify() against a bcrypt hash in
//    tally.json's calibration.password_hash — this page cannot SET that
//    hash itself (reduces what a stumbled-upon URL can actually do to
//    nothing, if no hash has been configured out-of-band — see README.md
//    for how the developer sets it).
//  - Activating starts an auto-expiring session (default 30-60 min,
//    configurable) enforced server-side via a timestamped state file, not
//    a browser cookie/session — so it can't be extended by just not
//    closing the tab.
//  - Fail-safe default: callbacks.sh's pluginStart deletes the session
//    file on every FPP boot, so calibration mode is always OFF after a
//    restart regardless of what it was before.
//  - Audit log: activation timestamps only, never content — see
//    calib_audit_log() below.
ini_set('display_errors', '0');

$CONFIG_FILE = "/home/fpp/media/config/tally.json";
$SESSION_FILE = "/home/fpp/media/plugins/fpp-tally/state/calib_session.json";
$AUDIT_LOG = "/home/fpp/media/logs/plugin-fpp-tally-calibration-audit.log";

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function calib_load_cfg($path) {
    if (!file_exists($path)) return [];
    $j = @json_decode(file_get_contents($path), true);
    return is_array($j) ? $j : [];
}

function calib_session_active($sessionFile) {
    if (!file_exists($sessionFile)) return [false, 0];
    $j = @json_decode(file_get_contents($sessionFile), true);
    if (!is_array($j) || empty($j['active']) || empty($j['expires_at'])) return [false, 0];
    $remaining = (int)$j['expires_at'] - time();
    if ($remaining <= 0) {
        @unlink($sessionFile);
        return [false, 0];
    }
    return [true, $remaining];
}

function calib_audit_log($logFile, $event) {
    @mkdir(dirname($logFile), 0755, true);
    $line = "[" . date('Y-m-d H:i:s') . "] {$event}\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

$cfg = calib_load_cfg($CONFIG_FILE);
$passwordHash = $cfg['calibration']['password_hash'] ?? '';
$timeoutMin = (int)($cfg['calibration']['session_timeout_min'] ?? 30);

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'activate') {
        if ($passwordHash === '') {
            $error = 'No calibration password is configured for this install. This page cannot set one itself — see README.md.';
        } else {
            $submitted = (string)($_POST['password'] ?? '');
            if (password_verify($submitted, $passwordHash)) {
                @mkdir(dirname($SESSION_FILE), 0755, true);
                $expiresAt = time() + max(1, $timeoutMin) * 60;
                @file_put_contents($SESSION_FILE, json_encode(['active' => true, 'expires_at' => $expiresAt]));
                calib_audit_log($AUDIT_LOG, "Calibration mode ACTIVATED (expires in {$timeoutMin}m)");
                $message = 'Calibration mode activated.';
            } else {
                $error = 'Incorrect password.';
                calib_audit_log($AUDIT_LOG, "Calibration mode activation FAILED (bad password)");
            }
        }
    } elseif ($action === 'deactivate') {
        @unlink($SESSION_FILE);
        calib_audit_log($AUDIT_LOG, "Calibration mode DEACTIVATED (manual)");
        $message = 'Calibration mode deactivated.';
    }
}

[$active, $remaining] = calib_session_active($SESSION_FILE);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Tally — Calibration (Developer Only)</title>
  <style>
    body { background:#1a1a1a; color:#eee; font-family: system-ui, sans-serif; padding: 2rem; max-width: 480px; margin: 0 auto; }
    .box { border: 1px solid #444; border-radius: 6px; padding: 1.5rem; }
    input[type=password] { width: 100%; padding: .5rem; margin: .5rem 0 1rem; background:#222; border:1px solid #555; color:#eee; border-radius:4px; }
    button { padding: .5rem 1rem; border-radius: 4px; border: none; cursor: pointer; font-weight: 600; }
    .btn-primary { background: #1a6eb5; color: #fff; }
    .btn-danger { background: #b02a37; color: #fff; }
    .error { color: #ff8080; margin-bottom: 1rem; }
    .ok { color: #8fd18f; margin-bottom: 1rem; }
    .muted { color: #999; font-size: .85rem; }
  </style>
</head>
<body>
  <div class="box">
    <h2>Tally Calibration Mode</h2>
    <p class="muted">Developer-only tool for visually validating LD2410B/MLX90640 thresholds against the calibration camera. Never part of a normal build.</p>

    <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="ok"><?= e($message) ?></div><?php endif; ?>

    <?php if ($active): ?>
      <p>Status: <strong style="color:#8fd18f;">ACTIVE</strong> — expires in <span id="calibRemaining"><?= (int)$remaining ?></span>s</p>
      <p class="muted">
        Live camera frame preview would render here once the camera-frame
        capture pipeline is implemented (not yet built — this route only
        implements the activation/expiry/audit gating from project spec
        section 6 so far).
      </p>
      <form method="post">
        <input type="hidden" name="action" value="deactivate">
        <button type="submit" class="btn-danger">Deactivate Now</button>
      </form>
      <script>
        let remaining = <?= (int)$remaining ?>;
        setInterval(() => {
          remaining--;
          document.getElementById('calibRemaining').textContent = Math.max(0, remaining);
          if (remaining <= 0) location.reload();
        }, 1000);
      </script>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="action" value="activate">
        <label>Password</label>
        <input type="password" name="password" autofocus>
        <button type="submit" class="btn-primary">Activate Calibration Mode</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
