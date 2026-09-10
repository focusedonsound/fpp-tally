<?php
// index.php — Tally Setup page
$configFile = "/home/fpp/media/config/tally.json";

function defaultCfg() {
    $example = @file_get_contents(dirname(__DIR__) . "/config/tally.json.example");
    $j = $example ? json_decode($example, true) : null;
    return is_array($j) ? $j : [];
}

function loadConfig($path) {
    $cfg = defaultCfg();
    if (file_exists($path)) {
        $j = @json_decode(file_get_contents($path), true);
        if (is_array($j)) $cfg = array_replace_recursive($cfg, $j);
    }
    return $cfg;
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function daemonRunning() {
    $pidFile = "/home/fpp/media/plugins/fpp-tally/state/tally_daemon.pid";
    if (!file_exists($pidFile)) return false;
    $pid = trim(@file_get_contents($pidFile));
    if (!$pid || !is_numeric($pid)) return false;
    // /proc/<pid> existing is enough on Linux (its own dir is always
    // world-executable) and, unlike posix_kill(), doesn't depend on the
    // calling user matching the daemon's — PHP-FPM runs as 'fpp', but the
    // daemon can end up owned by root (e.g. a manual boot-time start, or a
    // builder testing over SSH as root), which makes posix_kill() report a
    // false EPERM negative even though the process is alive.
    if (is_dir("/proc/$pid")) return true;
    if (function_exists('posix_kill')) return posix_kill((int)$pid, 0);
    return false;
}

function listSerialPorts() {
    $ports = [];
    foreach ((array)@glob('/dev/ttyUSB*') as $p) $ports[] = $p;
    foreach ((array)@glob('/dev/ttyACM*') as $p) $ports[] = $p;
    sort($ports);
    return $ports;
}

function listPlaylists() {
    $dir = "/home/fpp/media/playlists";
    $out = [];
    if (is_dir($dir)) {
        foreach (glob("$dir/*.json") as $f) {
            $out[] = basename($f, '.json');
        }
    }
    sort($out);
    return $out;
}

$cfg         = loadConfig($configFile);
$running     = daemonRunning();
$serialPorts = listSerialPorts();
$playlists   = listPlaylists();

$mods = $cfg['modules'] ?? [];
$reg  = $cfg['registration'] ?? [];
$zEnt = $cfg['zones']['entrance'] ?? [];
$zDrv = $cfg['zones']['driveway'] ?? [];
$ld   = $cfg['ld2410'] ?? [];
$th   = $cfg['thermal'] ?? [];
$cs   = $cfg['crowd_scan'] ?? [];
$bme  = $cfg['bme280'] ?? [];
$trig = $cfg['triggers'] ?? [];
$mqtt = $cfg['mqtt'] ?? [];
?>
<style>
.tally-btn {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .35rem .8rem; font-size: .875rem; font-weight: 500; line-height: 1.5;
  text-align: center; white-space: nowrap; cursor: pointer;
  border: 1px solid #3a7fc1; border-radius: .3rem; text-decoration: none !important;
  background-color: #1a6eb5; color: #fff !important;
  transition: background-color .15s, border-color .15s; vertical-align: middle;
}
.tally-btn:hover, .tally-btn:focus { background-color: #155a94; border-color: #0e4370; color: #fff !important; }
.tally-btn:disabled, .tally-btn.disabled { opacity: .55; cursor: not-allowed; pointer-events: none; }
.tally-btn-sm { padding: .2rem .5rem; font-size: .8rem; border-radius: .25rem; }
.tally-btn-danger { background-color: #b02a37; border-color: #842029; }
.tally-btn-danger:hover { background-color: #842029; border-color: #6a1a20; }
.tally-btn-secondary { background-color: #6c757d; border-color: #6c757d; }
.tally-btn-secondary:hover, .tally-btn-secondary:focus { background-color: #5c636a; border-color: #565e64; }
.tally-card { border: 1px solid rgba(255,255,255,0.12); border-radius: .4rem; padding: 1rem; margin-bottom: 1rem; }
.tally-card h4 { margin-top: 0; }
.tally-hw-disabled { opacity: 0.4; pointer-events: none; }
.tally-badge { padding: .25rem .6rem; border-radius: .3rem; font-size: .8rem; font-weight: 600; }
.tally-badge-on { background: #1e7e34; color: #fff; }
.tally-badge-off { background: #6c757d; color: #fff; }
</style>

<h3><i class="fas fa-fw fa-car"></i> Tally — Vehicle &amp; Crowd Counter</h3>

<div class="tally-card">
  <h4><i class="fas fa-fw fa-power-off"></i> Daemon Controls</h4>
  <p>
    Status:
    <span id="tallyDaemonBadge" class="tally-badge <?= $running ? 'tally-badge-on' : 'tally-badge-off' ?>">
      <?= $running ? 'Running' : 'Stopped' ?>
    </span>
  </p>
  <button class="tally-btn" onclick="tallyControl('start')"><i class="fas fa-play"></i> Start</button>
  <button class="tally-btn tally-btn-secondary" onclick="tallyControl('restart')"><i class="fas fa-rotate"></i> Restart</button>
  <button class="tally-btn tally-btn-danger" onclick="tallyControl('stop')"><i class="fas fa-stop"></i> Stop</button>
  <a class="tally-btn tally-btn-secondary" href="plugin.php?plugin=fpp-tally&page=www/reporting.php">
    <i class="fas fa-chart-line"></i> Open Reporting
  </a>
</div>

<form id="tallyForm">

<div class="tally-card">
  <h4><i class="fas fa-fw fa-id-card"></i> Registration</h4>
  <p class="text-muted small">
    Free, one field, no password. Registration unlocks this Setup page and the Reporting
    page — it does <strong>not</strong> gate detection, logging, MQTT publishing, or FPP
    triggers, which run whether or not you've registered.
  </p>
  <div class="mb-2">
    <label class="form-label">Email</label>
    <input type="email" class="form-control" name="reg_email" value="<?= e($reg['email'] ?? '') ?>">
  </div>
  <div class="mb-2">
    <label class="form-label">License Key <span class="text-muted">(optional — no premium tier defined yet)</span></label>
    <input type="text" class="form-control" name="reg_license_key" value="<?= e($reg['license_key'] ?? '') ?>">
  </div>
  <span class="tally-badge <?= !empty($reg['registered']) ? 'tally-badge-on' : 'tally-badge-off' ?>">
    <?= !empty($reg['registered']) ? 'Registered' : 'Not Registered' ?>
  </span>
</div>

<div class="tally-card">
  <h4><i class="fas fa-fw fa-microchip"></i> Hardware Selection</h4>
  <p class="text-muted small">
    Check what's physically connected to this Pi. Sections below appear/disappear to match —
    Options 1 and 2 are not mutually exclusive; run either alone, or both for a full
    entrance+driveway picture.
  </p>
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" name="mod_ld2410" id="modLd2410" value="1"
           <?= !empty($mods['ld2410']) ? 'checked' : '' ?> onchange="tallyToggleSection('ld2410', this.checked)">
    <label class="form-check-label" for="modLd2410">HLK-LD2410B radar (Option 1 — driveway/zone)</label>
  </div>
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" name="mod_thermal" id="modThermal" value="1"
           <?= !empty($mods['thermal']) ? 'checked' : '' ?> onchange="tallyToggleSection('thermal', this.checked)">
    <label class="form-check-label" for="modThermal">
      MLX90640 thermal array (Option 2 — entrance/street zone)
      <span class="badge bg-danger">not yet hardware-validated</span>
    </label>
  </div>
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" name="mod_crowd_ble" id="modCrowdBle" value="1"
           <?= !empty($mods['crowd_ble']) ? 'checked' : '' ?> onchange="tallyToggleSection('crowd', this.checked || document.getElementById('modCrowdWifi').checked)">
    <label class="form-check-label" for="modCrowdBle">
      BLE crowd/device estimate (Option 3a — onboard Bluetooth, free)
    </label>
  </div>
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" name="mod_crowd_wifi" id="modCrowdWifi" value="1"
           <?= !empty($mods['crowd_wifi']) ? 'checked' : '' ?> onchange="tallyToggleSection('crowd', this.checked || document.getElementById('modCrowdBle').checked)">
    <label class="form-check-label" for="modCrowdWifi">
      WiFi crowd/device estimate (Option 3b — second USB adapter, monitor mode)
      <span class="badge bg-danger">needs a privileged daemon — see docs</span>
    </label>
  </div>
  <div class="form-check form-switch">
    <input class="form-check-input" type="checkbox" name="mod_bme280" id="modBme280" value="1"
           <?= !empty($mods['bme280']) ? 'checked' : '' ?> onchange="tallyToggleSection('bme280', this.checked)">
    <label class="form-check-label" for="modBme280">
      BME280 temperature/humidity (Option 4)
      <span class="badge bg-danger">not yet hardware-validated</span>
    </label>
  </div>
</div>

<div class="tally-card" id="section-ld2410" <?= empty($mods['ld2410']) ? 'style="display:none;"' : '' ?>>
  <h4><i class="fas fa-fw fa-satellite-dish"></i> LD2410B Config — Driveway Zone</h4>
  <div class="row g-2">
    <div class="col-md-6">
      <label class="form-label">Zone label</label>
      <input type="text" class="form-control" name="driveway_label" value="<?= e($zDrv['label'] ?? 'Driveway') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Direction A label</label>
      <input type="text" class="form-control" name="driveway_dir_a" value="<?= e($zDrv['direction_a_label'] ?? 'Inbound') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Direction B label</label>
      <input type="text" class="form-control" name="driveway_dir_b" value="<?= e($zDrv['direction_b_label'] ?? 'Outbound') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Radar A serial port</label>
      <select class="form-control" name="ld2410_port_a">
        <?php $curA = $ld['A']['port'] ?? '/dev/ttyUSB0'; ?>
        <?php foreach (array_unique(array_merge($serialPorts, [$curA])) as $p): ?>
          <option value="<?= e($p) ?>" <?= $p === $curA ? 'selected' : '' ?>><?= e($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Radar B serial port</label>
      <select class="form-control" name="ld2410_port_b">
        <?php $curB = $ld['B']['port'] ?? '/dev/ttyUSB1'; ?>
        <?php foreach (array_unique(array_merge($serialPorts, [$curB])) as $p): ?>
          <option value="<?= e($p) ?>" <?= $p === $curB ? 'selected' : '' ?>><?= e($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">"A → B" counts as direction</label>
      <select class="form-control" name="ld2410_toward_reference">
        <option value="A_to_B" <?= ($ld['toward_reference'] ?? 'A_to_B') === 'A_to_B' ? 'selected' : '' ?>>Direction A</option>
        <option value="B_to_A" <?= ($ld['toward_reference'] ?? '') === 'B_to_A' ? 'selected' : '' ?>>Direction B</option>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Sequence window (seconds)</label>
      <input type="number" step="0.1" class="form-control" name="ld2410_seq_window_s" value="<?= e($ld['sequence_window_s'] ?? 0.8) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Parked timeout (seconds)</label>
      <input type="number" class="form-control" name="ld2410_parked_timeout_s" value="<?= e($ld['parked_timeout_s'] ?? 180) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Minimum energy (0-100)</label>
      <input type="number" class="form-control" name="ld2410_min_energy" value="<?= e($ld['min_energy'] ?? 20) ?>">
    </div>
  </div>
  <div class="mt-2">
    <button type="button" class="tally-btn tally-btn-sm" onclick="tallyTestVehicle('driveway')">
      <i class="fas fa-vial"></i> Trigger Test Vehicle Event (Driveway)
    </button>
  </div>
</div>

<div class="tally-card" id="section-thermal" <?= empty($mods['thermal']) ? 'style="display:none;"' : '' ?>>
  <h4><i class="fas fa-fw fa-temperature-high"></i> Thermal Config — Entrance Zone
    <span class="badge bg-danger">not yet hardware-validated</span>
  </h4>
  <p class="text-muted small">
    Detection logic (blob tracking, direction, parked dwell, speed estimate) is implemented,
    but has not been tested against a real MLX90640 — treat the thresholds below as a starting
    point to tune once you have the sensor wired up, not as pre-calibrated values.
  </p>
  <div class="row g-2">
    <div class="col-md-6">
      <label class="form-label">Zone label</label>
      <input type="text" class="form-control" name="entrance_label" value="<?= e($zEnt['label'] ?? 'Entrance') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Direction A label</label>
      <input type="text" class="form-control" name="entrance_dir_a" value="<?= e($zEnt['direction_a_label'] ?? 'Inbound') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Direction B label</label>
      <input type="text" class="form-control" name="entrance_dir_b" value="<?= e($zEnt['direction_b_label'] ?? 'Outbound') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">I2C bus</label>
      <input type="number" class="form-control" name="thermal_i2c_bus" value="<?= e($th['i2c_bus'] ?? 1) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">I2C address</label>
      <input type="text" class="form-control" name="thermal_i2c_address" value="<?= e($th['i2c_address'] ?? '0x33') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Frame rate (Hz)</label>
      <input type="number" class="form-control" name="thermal_frame_rate_hz" value="<?= e($th['frame_rate_hz'] ?? 4) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Min blob size (px)</label>
      <input type="number" class="form-control" name="thermal_min_blob_size" value="<?= e($th['min_blob_size'] ?? 6) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Blob threshold (°C above background)</label>
      <input type="number" step="0.1" class="form-control" name="thermal_delta_threshold_c" value="<?= e($th['delta_threshold_c'] ?? 2.0) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Min travel to count as a pass (columns)</label>
      <input type="number" step="1" class="form-control" name="thermal_min_travel_cols" value="<?= e($th['min_travel_cols'] ?? 4) ?>">
    </div>
    <div class="col-md-4 d-flex align-items-end">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="thermal_flip_direction" value="1" <?= !empty($th['flip_direction']) ? 'checked' : '' ?>>
        <label class="form-check-label">Flip direction (swap A/B without re-mounting)</label>
      </div>
    </div>
    <div class="col-md-4">
      <label class="form-label">Mount height (m)</label>
      <input type="number" step="0.1" class="form-control" name="thermal_mount_height_m" value="<?= e($th['mount_height_m'] ?? 3.0) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Mount angle (deg, 90=perpendicular)</label>
      <input type="number" step="1" class="form-control" name="thermal_mount_angle_deg" value="<?= e($th['mount_angle_deg'] ?? 90) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Mount distance to road (m)</label>
      <input type="number" step="0.1" class="form-control" name="thermal_mount_distance_m" value="<?= e($th['mount_distance_m'] ?? 5.0) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Parked timeout (seconds)</label>
      <input type="number" class="form-control" name="thermal_parked_timeout_s" value="<?= e($th['parked_timeout_s'] ?? 180) ?>">
    </div>
  </div>
  <div class="mt-2">
    <button type="button" class="tally-btn tally-btn-sm" onclick="tallyTestVehicle('entrance')">
      <i class="fas fa-vial"></i> Trigger Test Vehicle Event (Entrance)
    </button>
  </div>
</div>

<div class="tally-card" id="section-crowd" <?= (empty($mods['crowd_ble']) && empty($mods['crowd_wifi'])) ? 'style="display:none;"' : '' ?>>
  <h4><i class="fas fa-fw fa-user-group"></i> Crowd Scan Config</h4>
  <p class="text-muted small">
    <strong>Modern devices randomize both BLE and WiFi identifiers by default</strong> — treat
    this as a relative crowd-density indicator, not an exact headcount. WiFi scanning also
    needs a raw-socket-capable interface in monitor mode and elevated daemon privileges Tally
    does not grant itself by default — see the Setup checklist in README.md before enabling it.
    When both BLE and WiFi are enabled, the higher of the two latest readings is published as
    the combined estimate (their identifiers can't be reliably deduplicated against each other,
    so summing them would double-count devices seen on both radios).
  </p>
  <div class="row g-2">
    <div class="col-md-4">
      <label class="form-label">WiFi monitor-mode interface</label>
      <input type="text" class="form-control" name="crowd_wifi_interface" value="<?= e($cs['wifi_interface'] ?? 'wlan1') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Scan interval (seconds)</label>
      <input type="number" class="form-control" name="crowd_interval_s" value="<?= e($cs['interval_s'] ?? 60) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Device offset (subtract always-present devices)</label>
      <input type="number" class="form-control" name="crowd_device_offset" value="<?= e($cs['device_offset'] ?? 0) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Crowd-size FPP trigger threshold</label>
      <input type="number" class="form-control" name="crowd_threshold" value="<?= e($cs['crowd_threshold'] ?? 0) ?>">
    </div>
  </div>
  <div class="mt-2">
    <button type="button" class="tally-btn tally-btn-sm" onclick="tallyTestCrowd()">
      <i class="fas fa-vial"></i> Trigger Test Crowd Scan
    </button>
  </div>
</div>

<div class="tally-card" id="section-bme280" <?= empty($mods['bme280']) ? 'style="display:none;"' : '' ?>>
  <h4><i class="fas fa-fw fa-cloud-sun"></i> BME280 Config
    <span class="badge bg-danger">not yet hardware-validated</span>
  </h4>
  <div class="row g-2">
    <div class="col-md-3">
      <label class="form-label">I2C bus</label>
      <input type="number" class="form-control" name="bme280_i2c_bus" value="<?= e($bme['i2c_bus'] ?? 1) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">I2C address</label>
      <input type="text" class="form-control" name="bme280_i2c_address" value="<?= e($bme['i2c_address'] ?? '0x76') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Poll interval (seconds)</label>
      <input type="number" class="form-control" name="bme280_poll_interval_s" value="<?= e($bme['poll_interval_s'] ?? 600) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Display unit</label>
      <select class="form-control" name="bme280_display_unit">
        <option value="F" <?= ($bme['display_unit'] ?? 'F') === 'F' ? 'selected' : '' ?>>°F</option>
        <option value="C" <?= ($bme['display_unit'] ?? '') === 'C' ? 'selected' : '' ?>>°C</option>
      </select>
    </div>
  </div>
</div>

<div class="tally-card">
  <h4><i class="fas fa-fw fa-bolt"></i> FPP Triggers</h4>
  <p class="text-muted small">Defers to FPP's native Scheduler for show hours — triggers only fire while a show playlist/schedule is running.</p>
  <?php
  $triggerRows = [
      'entrance_direction_a' => 'Entrance — Direction A pass',
      'entrance_direction_b' => 'Entrance — Direction B pass',
      'entrance_parked'      => 'Entrance — Parked',
      'driveway_direction_a' => 'Driveway — Direction A pass',
      'driveway_direction_b' => 'Driveway — Direction B pass',
      'driveway_parked'      => 'Driveway — Parked',
      'crowd_threshold'      => 'Crowd size crosses threshold',
  ];
  foreach ($triggerRows as $key => $rowLabel):
      $t = $trig[$key] ?? [];
  ?>
  <div class="row g-2 align-items-center mb-2 pb-2" style="border-bottom:1px solid rgba(255,255,255,0.08);">
    <div class="col-md-3">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="trig_<?= $key ?>_enabled" value="1" <?= !empty($t['enabled']) ? 'checked' : '' ?>>
        <label class="form-check-label"><?= e($rowLabel) ?></label>
      </div>
    </div>
    <div class="col-md-4">
      <select class="form-control" name="trig_<?= $key ?>_playlist">
        <option value="">-- none --</option>
        <?php foreach ($playlists as $p): ?>
          <option value="<?= e($p) ?>" <?= ($t['playlist'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <input type="number" class="form-control" name="trig_<?= $key ?>_cooldown_s" placeholder="Cooldown (s)" value="<?= e($t['cooldown_s'] ?? 30) ?>">
    </div>
    <div class="col-md-3">
      <input type="number" class="form-control" name="trig_<?= $key ?>_play_timeout_s" placeholder="Play timeout (s)" value="<?= e($t['play_timeout_s'] ?? 60) ?>">
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="tally-card">
  <h4><i class="fas fa-fw fa-calendar-days"></i> Schedule</h4>
  <p class="text-muted small">
    Tally logs 24/7 for accurate daily totals — this runs independent of your show's active hours.
    FPP effect triggers above only fire while your show's active playlist/schedule is running;
    Tally does not implement its own show-hours logic — set that in FPP's native Scheduler.
  </p>
</div>

<div class="tally-card">
  <h4><i class="fas fa-fw fa-network-wired"></i> MQTT / Home Assistant</h4>
  <div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" name="mqtt_enabled" value="1" <?= !empty($mqtt['enabled']) ? 'checked' : '' ?>>
    <label class="form-check-label">Enable MQTT publishing</label>
  </div>
  <p class="text-muted small">Broker connection defaults to FPP's own MQTT settings (System Settings → MQTT). Override only if you need Tally on a different broker.</p>
  <div class="row g-2">
    <div class="col-md-6">
      <label class="form-label">Topic base</label>
      <input type="text" class="form-control" name="mqtt_base" value="<?= e($mqtt['base'] ?? 'tally') ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Device name</label>
      <input type="text" class="form-control" name="mqtt_device_name" value="<?= e($mqtt['device_name'] ?? 'Tally Vehicle Counter') ?>">
    </div>
  </div>
</div>

<div class="tally-card">
  <div class="form-check form-switch mb-2">
    <input class="form-check-input" type="checkbox" name="enabled" value="1" <?= ($cfg['enabled'] ?? true) ? 'checked' : '' ?>>
    <label class="form-check-label"><strong>Plugin Enabled</strong></label>
  </div>
  <button type="button" class="tally-btn" onclick="tallySave()"><i class="fas fa-save"></i> Save Settings</button>
</div>

</form>

<script>
function tallyToggleSection(name, show) {
  const el = document.getElementById('section-' + name);
  if (el) el.style.display = show ? '' : 'none';
}

async function tallySave() {
  const form = document.getElementById('tallyForm');
  const fd = new FormData(form);
  try {
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/save.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const j = await res.json();
    if (typeof $ !== 'undefined' && $.jGrowl) {
      $.jGrowl(j.message || (j.status === 'OK' ? 'Saved.' : 'Save failed.'), { themeState: j.status === 'OK' ? 'success' : 'danger' });
    } else {
      alert(j.message || j.status);
    }
  } catch (e) {
    alert('Save error: ' + e.message);
  }
}

async function tallyControl(action) {
  try {
    const fd = new FormData(); fd.append('action', action);
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/control.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const j = await res.json();
    if (typeof $ !== 'undefined' && $.jGrowl) {
      $.jGrowl(j.message || j.status, { themeState: j.status === 'OK' ? 'success' : 'danger' });
    }
    setTimeout(() => location.reload(), 1500);
  } catch (e) {
    alert('Control error: ' + e.message);
  }
}

async function tallyTestVehicle(zone) {
  const fd = new FormData(); fd.append('action', 'vehicle'); fd.append('zone', zone);
  const res = await fetch('plugin.php?plugin=fpp-tally&page=www/trigger.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
  const j = await res.json();
  if (typeof $ !== 'undefined' && $.jGrowl) $.jGrowl(j.message, { themeState: j.status === 'OK' ? 'success' : 'danger' });
}

async function tallyTestCrowd() {
  const fd = new FormData(); fd.append('action', 'crowd');
  const res = await fetch('plugin.php?plugin=fpp-tally&page=www/trigger.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
  const j = await res.json();
  if (typeof $ !== 'undefined' && $.jGrowl) $.jGrowl(j.message, { themeState: j.status === 'OK' ? 'success' : 'danger' });
}
</script>
