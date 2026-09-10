<?php
// control.php — Tally daemon start/stop/restart, via the sudoers rule
// fpp_install.sh installs for the fpp user (see /etc/sudoers.d/tally).
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($ok, $msg) {
    echo json_encode(["status" => $ok ? "OK" : "ERROR", "message" => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond(false, 'POST required');
}

$action = trim($_POST['action'] ?? '');
if (!in_array($action, ['start', 'stop', 'restart'], true)) {
    respond(false, 'Unknown action');
}

$out = shell_exec("sudo systemctl {$action} tally 2>&1");
$state = trim(shell_exec("sudo systemctl is-active tally 2>/dev/null") ?: "unknown");

respond(true, ucfirst($action) . " requested. Service is now: {$state}" . ($out ? " ({$out})" : ""));
