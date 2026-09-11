<?php
// diag_camera_stats.php — Diagnostics page's camera-assisted tuning panel
// data source. Read-only: mirrors stats.php's SQLite3 read-only-open
// pattern rather than inventing a new DB-access approach.
//
// Queries pass_features (written by tally_daemon.py's
// _handle_classification_event, one row per radar pass the camera module
// was able to classify -- see camera.py's docstring) for vehicle vs.
// not_vehicle peak-energy stats, and a suggested min_energy threshold. This
// endpoint only reads and summarizes; nothing here ever changes the radar
// threshold -- that still requires the user to press "Apply" on the
// Diagnostics page, which posts to diag_tune.php's existing set_min_energy
// action, same as the manual slider.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$DB_PATH = "/home/fpp/media/plugins/fpp-tally/state/tally.db";

// Keep these in sync with tally_db.py's VEHICLE_LABELS / NON_VEHICLE_LABELS.
$VEHICLE_LABELS     = ['car', 'truck', 'bus', 'motorcycle'];
$NON_VEHICLE_LABELS = ['person', 'dog', 'cat', 'bicycle'];
$MIN_SAMPLES_FOR_SUGGESTION = 15;

$result = [
    "vehicle" => ["count" => 0, "min_energy" => null, "max_energy" => null, "avg_energy" => null],
    "not_vehicle" => ["count" => 0, "min_energy" => null, "max_energy" => null, "avg_energy" => null],
    "suggested_min_energy" => null,
];

if (!file_exists($DB_PATH)) {
    echo json_encode($result);
    exit;
}

function bucketStats(SQLite3 $db, array $labels): array {
    $placeholders = implode(',', array_fill(0, count($labels), '?'));
    $stmt = $db->prepare(
        "SELECT COUNT(*) n, MIN(peak_energy) lo, MAX(peak_energy) hi, AVG(peak_energy) avg " .
        "FROM pass_features WHERE camera_label IN ($placeholders) AND peak_energy IS NOT NULL"
    );
    foreach ($labels as $i => $label) {
        $stmt->bindValue($i + 1, $label, SQLITE3_TEXT);
    }
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return [
        "count" => (int)($row['n'] ?? 0),
        "min_energy" => $row['lo'] !== null ? (int)$row['lo'] : null,
        "max_energy" => $row['hi'] !== null ? (int)$row['hi'] : null,
        "avg_energy" => $row['avg'] !== null ? round($row['avg'], 1) : null,
    ];
}

try {
    $db = new SQLite3($DB_PATH, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(3000);

    $vehicle = bucketStats($db, $VEHICLE_LABELS);
    $notVehicle = bucketStats($db, $NON_VEHICLE_LABELS);

    $suggested = null;
    if ($vehicle['count'] >= $MIN_SAMPLES_FOR_SUGGESTION
        && $notVehicle['count'] >= $MIN_SAMPLES_FOR_SUGGESTION
        && $vehicle['min_energy'] !== null) {
        // Lowest confirmed-vehicle peak energy -- a threshold here keeps
        // every vehicle observed so far. Only offered once there's enough
        // of both classes to be meaningful.
        $suggested = $vehicle['min_energy'];
    }

    $result['vehicle'] = $vehicle;
    $result['not_vehicle'] = $notVehicle;
    $result['suggested_min_energy'] = $suggested;
    $result['min_samples_required'] = $MIN_SAMPLES_FOR_SUGGESTION;
} catch (Exception $e) {
    // Table may not exist yet on a fresh install (no pass has been
    // classified) -- report as "no data" rather than a hard error.
}

echo json_encode($result);
