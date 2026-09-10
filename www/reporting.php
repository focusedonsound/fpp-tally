<?php
// reporting.php — Tally Reporting page.
// v0.1.0: live counters + recent-events table, polled from status.php.
// The 7/30/90/365-day Chart.js views (project spec section 11) are planned
// for a follow-up release once the sensor modules are producing real data
// to chart — see README.md's roadmap section.
ini_set('display_errors', '0');
?>
<style>
.tally-badge { padding: .25rem .6rem; border-radius: .3rem; font-size: .8rem; font-weight: 600; }
.tally-badge-on { background: #1e7e34; color: #fff; }
.tally-badge-off { background: #6c757d; color: #fff; }
.tally-card { border: 1px solid rgba(255,255,255,0.12); border-radius: .4rem; padding: 1rem; margin-bottom: 1rem; }
.tally-metric { font-size: 1.8rem; font-weight: 700; line-height: 1; }
.tally-metric-label { font-size: .8rem; color: #999; margin-top: 2px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h3 class="mb-0"><i class="fas fa-fw fa-chart-line"></i> Tally Reporting</h3>
  <span id="tallyRepDaemonBadge" class="tally-badge tally-badge-off">Loading&hellip;</span>
</div>

<div id="tallyZones"></div>

<div class="tally-card" id="tallyCrowdCard" style="display:none;">
  <h4><i class="fas fa-fw fa-user-group"></i> Estimated Devices Nearby</h4>
  <div class="tally-metric" id="tallyCrowdVal">—</div>
  <div class="tally-metric-label" id="tallyCrowdTs"></div>
</div>

<div class="tally-card" id="tallyEnvCard" style="display:none;">
  <h4><i class="fas fa-fw fa-cloud-sun"></i> Environment</h4>
  <div class="row">
    <div class="col-6">
      <div class="tally-metric" id="tallyTempVal">—</div>
      <div class="tally-metric-label">Temperature</div>
    </div>
    <div class="col-6">
      <div class="tally-metric" id="tallyHumidVal">—</div>
      <div class="tally-metric-label">Humidity</div>
    </div>
  </div>
</div>

<div class="tally-card">
  <h4><i class="fas fa-fw fa-list-ul"></i> Recent Events</h4>
  <div class="table-responsive">
    <table class="table table-sm">
      <thead><tr><th>Time</th><th>Zone</th><th>Source</th><th>Type</th><th>Direction</th><th>Dwell (s)</th></tr></thead>
      <tbody id="tallyRecentBody"><tr><td colspan="6" class="text-muted">Loading&hellip;</td></tr></tbody>
    </table>
  </div>
</div>

<script>
function tallyEsc(s) { return (s ?? '').toString().replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function tallyRenderZones(zones) {
  const box = document.getElementById('tallyZones');
  const keys = Object.keys(zones || {});
  if (keys.length === 0) {
    box.innerHTML = '<div class="tally-card text-muted">No zones with an enabled module yet — enable a hardware module on the Setup page.</div>';
    return;
  }
  box.innerHTML = keys.map(k => {
    const z = zones[k];
    return `<div class="tally-card">
      <h4><i class="fas fa-fw fa-map-pin"></i> ${tallyEsc(z.label)}</h4>
      <div class="row g-3">
        <div class="col-6 col-md-3"><div class="tally-metric">${z.cars_today}</div><div class="tally-metric-label">Cars Today</div></div>
        <div class="col-6 col-md-3"><div class="tally-metric">${z.cars_total}</div><div class="tally-metric-label">Cars Total</div></div>
        <div class="col-6 col-md-3"><div class="tally-metric">${z.dir_a_today}</div><div class="tally-metric-label">${tallyEsc(z.label_a)} Today</div></div>
        <div class="col-6 col-md-3"><div class="tally-metric">${z.dir_b_today}</div><div class="tally-metric-label">${tallyEsc(z.label_b)} Today</div></div>
        <div class="col-6 col-md-4"><div class="tally-metric">${z.parked_today}</div><div class="tally-metric-label">Parked Today</div></div>
        <div class="col-6 col-md-4"><div class="tally-metric">${z.parked_total}</div><div class="tally-metric-label">Parked Total</div></div>
        <div class="col-6 col-md-4"><div class="tally-metric">${z.parked_pct}%</div><div class="tally-metric-label">Parked Conversion</div></div>
      </div>
    </div>`;
  }).join('');
}

function tallyRenderRecent(rows) {
  const body = document.getElementById('tallyRecentBody');
  if (!rows || rows.length === 0) {
    body.innerHTML = '<tr><td colspan="6" class="text-muted">No events yet</td></tr>';
    return;
  }
  body.innerHTML = rows.map(r => `<tr>
    <td>${tallyEsc((r.timestamp || '').slice(0, 19).replace('T', ' '))}</td>
    <td>${tallyEsc(r.zone)}</td>
    <td>${tallyEsc(r.sensor_source)}</td>
    <td>${tallyEsc(r.event_type)}</td>
    <td>${tallyEsc(r.direction || '—')}</td>
    <td>${r.dwell_duration_s ?? '—'}</td>
  </tr>`).join('');
}

async function tallyRefresh() {
  try {
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/status.php&nopage=1', { cache: 'no-store' });
    const data = await res.json();
    if (data.error) throw new Error(data.error);

    const badge = document.getElementById('tallyRepDaemonBadge');
    badge.textContent = data.daemon_running ? 'Daemon Running' : 'Daemon Stopped';
    badge.className = 'tally-badge ' + (data.daemon_running ? 'tally-badge-on' : 'tally-badge-off');

    tallyRenderZones(data.zones);

    if (data.crowd) {
      document.getElementById('tallyCrowdCard').style.display = '';
      document.getElementById('tallyCrowdVal').textContent = data.crowd.adjusted_count;
      document.getElementById('tallyCrowdTs').textContent = 'Last scan: ' + (data.crowd.timestamp || '').slice(0, 19).replace('T', ' ');
    }

    if (data.environment) {
      document.getElementById('tallyEnvCard').style.display = '';
      document.getElementById('tallyTempVal').textContent = (data.environment.temperature_f != null) ? data.environment.temperature_f + '°F' : '—';
      document.getElementById('tallyHumidVal').textContent = (data.environment.humidity_pct != null) ? data.environment.humidity_pct + '%' : '—';
    }

    tallyRenderRecent(data.recent);
  } catch (e) {
    console.error('[Tally] refresh failed:', e);
  }
}

tallyRefresh();
setInterval(tallyRefresh, 15000);
</script>
