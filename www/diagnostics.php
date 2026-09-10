<?php
// diagnostics.php — Tally Diagnostics page.
// Live-only view (never touches the database) of exactly what the
// sensors are currently seeing: per-gate LD2410B engineering-mode energy
// for sides A/B, and the raw BLE/WiFi address lists from the most recent
// crowd scan. Polls diag_status.php, which itself just reads the
// ephemeral live-state JSON files each module writes.
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
.diag-legend { font-size: .75rem; color: #999; margin-bottom: .5rem; }
.diag-legend .sw { display: inline-block; width: .7rem; height: .7rem; border-radius: 2px; margin-right: .25rem; vertical-align: middle; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h3 class="mb-0"><i class="fas fa-fw fa-satellite-dish"></i> Tally Diagnostics</h3>
  <span class="text-muted small">Live view only — nothing on this page is written to the database.</span>
</div>

<div class="tally-card" id="diagLd2410Card">
  <h4><i class="fas fa-fw fa-radar"></i> LD2410B Radar — Per-Gate Readout</h4>
  <div class="diag-legend">
    <span class="sw" style="background:#36a2eb;"></span> Moving energy
    &nbsp;&nbsp;<span class="sw" style="background:#ff9f40;"></span> Static energy
    &nbsp;&nbsp;each gate ≈ 0.75&nbsp;m, gate 0 nearest the sensor
  </div>
  <div id="diagLd2410Body" class="row g-4">
    <div class="col-12 text-muted small">Loading…</div>
  </div>
</div>

<div class="row">
  <div class="col-md-6">
    <div class="tally-card" id="diagBleCard">
      <h4><i class="fas fa-fw fa-bluetooth-b"></i> Raw BLE Scan</h4>
      <div id="diagBleBody" class="text-muted small">Loading…</div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="tally-card" id="diagWifiCard">
      <h4><i class="fas fa-fw fa-wifi"></i> Raw WiFi Probe-Request Scan</h4>
      <div id="diagWifiBody" class="text-muted small">Loading…</div>
    </div>
  </div>
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

async function diagPoll() {
  let data;
  try {
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_status.php&nopage=1', { cache: 'no-store' });
    data = await res.json();
  } catch (e) {
    return;
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

  diagAddrList(document.getElementById('diagBleBody'), data.crowd_ble, data.modules.crowd_ble, null);
  diagAddrList(document.getElementById('diagWifiBody'), data.crowd_wifi, data.modules.crowd_wifi,
    `Interface: ${data.wifi_interface}. Requires monitor mode + elevated privileges — see the Setup page's Crowd Scan Config warning if this stays empty.`);
}

diagPoll();
const diagInterval = setInterval(diagPoll, 1000);
window.addEventListener('beforeunload', () => clearInterval(diagInterval));
</script>
