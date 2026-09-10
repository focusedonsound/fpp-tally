<?php
// diagnostics.php — Tally Diagnostics page.
// Live-only view (never touches the database) of exactly what the
// sensors are currently seeing: a camera preview for visual reference,
// per-gate LD2410B engineering-mode energy for sides A/B, the MLX90640
// thermal delta grid, and the raw BLE/WiFi address lists from the most
// recent crowd scan. Camera + radar/thermal are shown together so you
// can watch a car cross the zone and see exactly which gate/blob
// corresponds to which real-world position, for tuning thresholds.
//
// The camera panel is unlocked by default (see calibration.require_
// password_on_diagnostics in tally.json/diag_snapshot.php) -- meant for
// active development on a build you already control physical/network
// access to. Flip that config flag on before handing this build to
// anyone else; see README.md.
ini_set('display_errors', '0');
?>
<style>
.tally-badge { padding: .25rem .6rem; border-radius: .3rem; font-size: .8rem; font-weight: 600; }
.tally-badge-on { background: #1e7e34; color: #fff; }
.tally-badge-off { background: #6c757d; color: #fff; }
.tally-badge-stale { background: #b5850a; color: #fff; }
.tally-card { border: 1px solid rgba(255,255,255,0.12); border-radius: .4rem; padding: 1rem; margin-bottom: 1rem; }
.diag-gatebar-row { display: flex; align-items: center; gap: .5rem; margin-bottom: 3px; }
.diag-gatebar-label { width: 3.2rem; font-size: .75rem; color: #999; text-align: right; flex-shrink: 0; }
.diag-gatebar-track { flex: 1; height: 14px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden; }
.diag-gatebar-fill { height: 100%; border-radius: 3px; transition: width .2s ease; }
.diag-gatebar-fill.move { background: #36a2eb; }
.diag-gatebar-fill.static { background: #ff9f40; }
.diag-gatebar-val { width: 2rem; font-size: .75rem; color: #ccc; text-align: right; flex-shrink: 0; }
.diag-side-title { font-size: .9rem; font-weight: 600; margin-bottom: .4rem; }
.diag-addr-list { font-family: monospace; font-size: .8rem; max-height: 220px; overflow-y: auto; margin: 0; padding-left: 1.1rem; }
.diag-addr-count { font-size: 1.6rem; font-weight: 700; }
.diag-ble-table { width: 100%; font-size: .8rem; border-collapse: collapse; white-space: nowrap; }
.diag-ble-table th { text-align: left; font-weight: 600; color: #999; padding: .3rem .6rem; border-bottom: 1px solid rgba(255,255,255,0.15); }
.diag-ble-table td { padding: .3rem .6rem; border-bottom: 1px solid rgba(255,255,255,0.06); }
.diag-ble-table td.mono { font-family: monospace; }
.diag-legend { font-size: .75rem; color: #999; margin-bottom: .5rem; }
.diag-legend .sw { display: inline-block; width: .7rem; height: .7rem; border-radius: 2px; margin-right: .25rem; vertical-align: middle; }
.diag-cam-frame { background: #000; border: 1px solid rgba(255,255,255,0.12); border-radius: .4rem; min-height: 220px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.diag-cam-frame img { max-width: 100%; display: block; }
.diag-cam-auth input[type=password] { width: 100%; padding: .5rem; margin: .5rem 0; background: rgba(255,255,255,0.06); border: 1px solid #555; color: inherit; border-radius: 4px; }
#diagThermalCanvas { border: 1px solid rgba(255,255,255,0.12); border-radius: .3rem; image-rendering: pixelated; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h3 class="mb-0"><i class="fas fa-fw fa-satellite-dish"></i> Tally Diagnostics</h3>
  <span class="text-muted small">Live view only — nothing on this page is written to the database.</span>
</div>

<div class="row">
  <div class="col-lg-5">
    <div class="tally-card" id="diagCamCard">
      <h4><i class="fas fa-fw fa-camera"></i> Camera Reference</h4>
      <div class="diag-cam-frame">
        <img id="diagCamImg" alt="camera preview" style="display:none;">
        <span id="diagCamMsg" class="text-muted small">Loading…</span>
      </div>
      <p class="text-muted small mt-2 mb-0" id="diagCamStatus"></p>
      <div id="diagCamAuth" class="diag-cam-auth mt-2" style="display:none;">
        <label class="form-label small mb-0">Calibration password required</label>
        <input type="password" id="diagCamPassword" placeholder="Password">
        <button type="button" class="tally-btn tally-btn-sm" onclick="diagCamActivate()">
          <i class="fas fa-unlock"></i> Unlock Camera
        </button>
        <div class="text-danger small mt-1" id="diagCamAuthError"></div>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="tally-card" id="diagLd2410Card">
      <h4><i class="fas fa-fw fa-satellite-dish"></i> LD2410B Radar — Per-Gate Readout</h4>
      <div class="diag-legend">
        <span class="sw" style="background:#36a2eb;"></span> Moving energy
        &nbsp;&nbsp;<span class="sw" style="background:#ff9f40;"></span> Static energy
        &nbsp;&nbsp;each gate ≈ 0.75&nbsp;m, gate 0 nearest the sensor
      </div>
      <div id="diagLd2410Body" class="row g-4">
        <div class="col-12 text-muted small">Loading…</div>
      </div>
    </div>
  </div>
</div>

<div class="tally-card" id="diagThermalCard">
  <h4><i class="fas fa-fw fa-fire"></i> MLX90640 Thermal — Delta Grid</h4>
  <div id="diagThermalBody">
    <div class="text-muted small">Loading…</div>
  </div>
</div>

<div class="tally-card" id="diagBleCard">
  <h4><i class="fas fa-fw fa-bluetooth-b"></i> Raw BLE Scan</h4>
  <div id="diagBleBody" class="text-muted small">Loading…</div>
</div>

<div class="tally-card" id="diagWifiCard">
  <h4><i class="fas fa-fw fa-wifi"></i> Raw WiFi Probe-Request Scan</h4>
  <div id="diagWifiBody" class="text-muted small">Loading…</div>
</div>

<script>
const DIAG_NUM_GATES = 9;

function diagBadge(text, cls) {
  return `<span class="tally-badge ${cls}">${text}</span>`;
}

function diagGateBars(side, sideData) {
  if (!sideData || !sideData.connected) {
    return `<div class="text-muted small">Not connected${sideData && sideData.port ? ' (' + sideData.port + ')' : ''}</div>`;
  }
  const stale = !!sideData.stale;
  const statusHtml = stale
    ? diagBadge('Stale', 'tally-badge-stale')
    : diagBadge(sideData.present ? 'Target' : 'Clear', sideData.present ? 'tally-badge-on' : 'tally-badge-off');

  let html = `<div class="d-flex justify-content-between align-items-center mb-2">
    <span>${statusHtml}</span>
    <span class="text-muted small">${sideData.engineering ? 'engineering mode' : 'basic mode'}</span>
  </div>`;

  if (!sideData.engineering || !sideData.gate_move_energy) {
    const moveE = sideData.move_energy ?? 0;
    const staticE = sideData.static_energy ?? 0;
    html += `<div class="text-muted small mb-1">Per-gate detail unavailable in basic mode.</div>`;
    html += `<div class="small">Move energy: <strong>${moveE}</strong> &nbsp; Static energy: <strong>${staticE}</strong></div>`;
    if (sideData.detect_dist_cm != null) {
      html += `<div class="small text-muted">Detected distance: ${sideData.detect_dist_cm} cm</div>`;
    }
    return html;
  }

  for (let g = 0; g < DIAG_NUM_GATES; g++) {
    const moveE = sideData.gate_move_energy[g] ?? 0;
    const staticE = sideData.gate_static_energy[g] ?? 0;
    const isMaxMove = g === sideData.max_move_gate;
    const isMaxStatic = g === sideData.max_static_gate;
    html += `<div class="diag-gatebar-row">
      <span class="diag-gatebar-label">G${g}${isMaxMove ? ' •' : ''}</span>
      <span class="diag-gatebar-track"><span class="diag-gatebar-fill move" style="width:${Math.min(100, moveE)}%"></span></span>
      <span class="diag-gatebar-val">${moveE}</span>
    </div>
    <div class="diag-gatebar-row">
      <span class="diag-gatebar-label">${isMaxStatic ? ' •' : ''}</span>
      <span class="diag-gatebar-track"><span class="diag-gatebar-fill static" style="width:${Math.min(100, staticE)}%"></span></span>
      <span class="diag-gatebar-val">${staticE}</span>
    </div>`;
  }
  return html;
}

function diagAddrList(bodyEl, data, moduleEnabled, offlineNote) {
  if (!moduleEnabled) {
    bodyEl.innerHTML = `<div class="text-muted">Module not enabled in Setup.</div>`;
    return;
  }
  if (!data) {
    bodyEl.innerHTML = `<div class="text-muted">No scan yet — waiting for the daemon's first scan window.</div>`;
    return;
  }
  const stale = !!data.stale;
  const addrs = data.addresses || [];
  let html = `<div class="d-flex align-items-center gap-2 mb-2">
    <span class="diag-addr-count">${addrs.length}</span>
    <span class="text-muted small">unique address${addrs.length === 1 ? '' : 'es'} in most recent scan</span>
    ${stale ? diagBadge('Stale', 'tally-badge-stale') : ''}
  </div>`;
  if (offlineNote) html += `<div class="text-muted small mb-2">${offlineNote}</div>`;
  if (addrs.length === 0) {
    html += `<div class="text-muted small">No devices seen in the most recent scan window.</div>`;
  } else {
    html += '<ol class="diag-addr-list">' + addrs.map(a => `<li>${a}</li>`).join('') + '</ol>';
  }
  bodyEl.innerHTML = html;
}

function diagRelTime(epochSeconds) {
  if (!epochSeconds) return '—';
  const s = Math.max(0, Math.round(Date.now() / 1000 - epochSeconds));
  if (s < 60) return s + 's ago';
  return Math.round(s / 60) + 'm ago';
}

// BLE gets its own richer table (address type, name, RSSI, vendor,
// first/last seen) instead of the plain address list WiFi uses --
// groundwork for eventually filtering the raw scan down to "likely a
// visitor's phone" vs. a fixed/paired peripheral that shows up every
// scan (see crowd_ble.py's module docstring). Nothing here is persisted;
// it only ever reflects the daemon's live-state file for the most recent
// scan window.
function diagBleTable(bodyEl, data, moduleEnabled) {
  if (!moduleEnabled) {
    bodyEl.innerHTML = `<div class="text-muted">Module not enabled in Setup.</div>`;
    return;
  }
  if (!data) {
    bodyEl.innerHTML = `<div class="text-muted">No scan yet — waiting for the daemon's first scan window.</div>`;
    return;
  }
  const stale = !!data.stale;
  const devices = data.devices || [];
  let html = `<div class="d-flex align-items-center gap-2 mb-2">
    <span class="diag-addr-count">${devices.length}</span>
    <span class="text-muted small">unique device${devices.length === 1 ? '' : 's'} in most recent scan</span>
    ${stale ? diagBadge('Stale', 'tally-badge-stale') : ''}
  </div>`;

  if (devices.length === 0) {
    html += `<div class="text-muted small">No devices seen in the most recent scan window.</div>`;
    bodyEl.innerHTML = html;
    return;
  }

  html += `<div style="overflow-x:auto;"><table class="diag-ble-table">
    <thead><tr>
      <th>Address</th><th>Type</th><th>Name</th><th>Vendor</th><th>RSSI</th><th>First seen</th><th>Last seen</th>
    </tr></thead><tbody>`;
  for (const d of devices) {
    html += `<tr>
      <td class="mono">${d.address}</td>
      <td>${d.address_type || '—'}</td>
      <td>${d.name ? d.name : '<span class="text-muted">—</span>'}</td>
      <td>${(d.vendors && d.vendors.length) ? d.vendors.join(', ') : '<span class="text-muted">—</span>'}</td>
      <td>${d.rssi != null ? d.rssi + ' dBm' : '—'}</td>
      <td>${diagRelTime(d.first_seen)}</td>
      <td>${diagRelTime(d.last_seen)}</td>
    </tr>`;
  }
  html += '</tbody></table></div>';
  bodyEl.innerHTML = html;
}

// --- Thermal grid -----------------------------------------------------
function diagThermalColor(delta, threshold) {
  // Simple black -> orange -> white heatmap, normalized against the
  // module's own foreground threshold so "just crossed into foreground"
  // reads as a visible warm color, not a barely-there tint.
  const t = Math.max(0, Math.min(1, delta / (threshold * 3)));
  const r = Math.round(255 * Math.min(1, t * 2));
  const g = Math.round(180 * Math.max(0, t * 2 - 0.5));
  const b = Math.round(60 * Math.max(0, t - 0.8) * 5);
  return `rgb(${r},${g},${b})`;
}

function diagRenderThermal(bodyEl, data, moduleEnabled) {
  if (!moduleEnabled) {
    bodyEl.innerHTML = `<div class="text-muted small">Module not enabled in Setup.</div>`;
    return;
  }
  if (!data) {
    bodyEl.innerHTML = `<div class="text-muted small">No thermal data yet — waiting for the daemon.</div>`;
    return;
  }

  let canvas = document.getElementById('diagThermalCanvas');
  if (!canvas) {
    bodyEl.innerHTML = '<canvas id="diagThermalCanvas"></canvas><div class="text-muted small mt-2" id="diagThermalMeta"></div>';
    canvas = document.getElementById('diagThermalCanvas');
  }
  const cellPx = 10;
  canvas.width = data.cols * cellPx;
  canvas.height = data.rows * cellPx;
  const ctx = canvas.getContext('2d');
  for (let r = 0; r < data.rows; r++) {
    for (let c = 0; c < data.cols; c++) {
      const v = data.delta_c[r * data.cols + c] ?? 0;
      ctx.fillStyle = diagThermalColor(v, data.delta_threshold_c);
      ctx.fillRect(c * cellPx, r * cellPx, cellPx, cellPx);
    }
  }
  ctx.strokeStyle = '#36a2eb';
  ctx.lineWidth = 2;
  for (const b of (data.blobs || [])) {
    ctx.beginPath();
    ctx.arc(b.col * cellPx + cellPx / 2, b.row * cellPx + cellPx / 2, cellPx * 1.2, 0, 2 * Math.PI);
    ctx.stroke();
  }

  const meta = document.getElementById('diagThermalMeta');
  if (meta) {
    const stale = data.stale ? diagBadge('Stale', 'tally-badge-stale') : '';
    meta.innerHTML = `${(data.blobs || []).length} blob(s) above threshold${data.tracking ? ` — tracking (dwell ${data.track_dwell_s ?? 0}s)` : ''} ${stale}`;
  }
}

// --- Camera -------------------------------------------------------------
let diagCamRequirePassword = false;
let diagCamAuthed = false;
let diagCamTimer = null;

const DIAG_CAM_MIN_GAP_MS = 1500;
let diagCamRunning = false;

function diagCamRefresh() {
  const img = document.getElementById('diagCamImg');
  const msg = document.getElementById('diagCamMsg');
  const status = document.getElementById('diagCamStatus');
  const probe = new Image();
  const startedAt = Date.now();

  // Self-chained via setTimeout scheduled from onload/onerror, NOT
  // setInterval -- each capture does a fresh V4L2 device open (ffmpeg),
  // which can take longer than the nominal poll gap on a Pi 3B+. A fixed
  // setInterval would fire the next request before the previous one
  // finished, and two overlapping captures fighting over the same
  // /dev/videoX produce alternating success/failure -- the camera
  // visibly "starting and stopping." Waiting for each request to settle
  // before scheduling the next guarantees only one capture in flight.
  const scheduleNext = () => {
    if (!diagCamRunning) return;
    const elapsed = Date.now() - startedAt;
    diagCamTimer = setTimeout(diagCamRefresh, Math.max(0, DIAG_CAM_MIN_GAP_MS - elapsed));
  };

  probe.onload = () => {
    img.src = probe.src;
    img.style.display = '';
    msg.style.display = 'none';
    status.textContent = 'Updated ' + new Date().toLocaleTimeString();
    scheduleNext();
  };
  probe.onerror = () => {
    img.style.display = 'none';
    msg.style.display = '';
    if (diagCamRequirePassword && !diagCamAuthed) {
      msg.textContent = 'Password required.';
      document.getElementById('diagCamAuth').style.display = '';
    } else {
      msg.textContent = 'Camera capture failed — check the configured device and the plugin log.';
    }
    scheduleNext();
  };
  probe.src = 'plugin.php?plugin=fpp-tally&page=www/diag_snapshot.php&nopage=1&t=' + Date.now();
}

function diagCamStart() {
  if (diagCamRunning) return;
  diagCamRunning = true;
  document.getElementById('diagCamAuth').style.display = 'none';
  diagCamRefresh();
}

async function diagCamActivate() {
  const pw = document.getElementById('diagCamPassword').value;
  const errEl = document.getElementById('diagCamAuthError');
  errEl.textContent = '';
  try {
    const fd = new FormData();
    fd.append('action', 'activate');
    fd.append('password', pw);
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_snapshot.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const data = await res.json();
    if (data.ok) {
      diagCamAuthed = true;
      diagCamStart();
    } else {
      errEl.textContent = data.error || 'Activation failed.';
    }
  } catch (e) {
    errEl.textContent = 'Request failed.';
  }
}

// --- Main poll loop -----------------------------------------------------
let diagCamInitDone = false;

async function diagPoll() {
  let data;
  try {
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_status.php&nopage=1', { cache: 'no-store' });
    data = await res.json();
  } catch (e) {
    return;
  }

  if (!diagCamInitDone) {
    diagCamInitDone = true;
    diagCamRequirePassword = !!(data.camera && data.camera.require_password);
    if (!diagCamRequirePassword) {
      diagCamStart();
    } else {
      // Try once unprompted in case a session from the hidden calibration
      // route (or an earlier unlock on this page) is already active.
      diagCamRefresh();
    }
  }

  const ld2410Body = document.getElementById('diagLd2410Body');
  if (!data.modules.ld2410) {
    ld2410Body.innerHTML = '<div class="col-12 text-muted small">Module not enabled in Setup.</div>';
  } else if (!data.ld2410) {
    ld2410Body.innerHTML = '<div class="col-12 text-muted small">No radar data yet — waiting for the daemon.</div>';
  } else {
    ld2410Body.innerHTML = `
      <div class="col-md-6">
        <div class="diag-side-title">Side A${data.ld2410.zone ? ' — ' + data.ld2410.zone : ''}</div>
        ${diagGateBars('A', data.ld2410.A)}
      </div>
      <div class="col-md-6">
        <div class="diag-side-title">Side B${data.ld2410.zone ? ' — ' + data.ld2410.zone : ''}</div>
        ${diagGateBars('B', data.ld2410.B)}
      </div>`;
  }

  diagRenderThermal(document.getElementById('diagThermalBody'), data.thermal, data.modules.thermal);

  diagBleTable(document.getElementById('diagBleBody'), data.crowd_ble, data.modules.crowd_ble);
  diagAddrList(document.getElementById('diagWifiBody'), data.crowd_wifi, data.modules.crowd_wifi,
    `Interface: ${data.wifi_interface}. Requires monitor mode + elevated privileges — see the Setup page's Crowd Scan Config warning if this stays empty.`);
}

diagPoll();
const diagInterval = setInterval(diagPoll, 1000);
window.addEventListener('beforeunload', () => {
  clearInterval(diagInterval);
  diagCamRunning = false;
  if (diagCamTimer) clearTimeout(diagCamTimer);
});
</script>
