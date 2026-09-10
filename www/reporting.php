<?php
// reporting.php — Tally Reporting page.
// Live counters + recent-events table (polled from status.php every 15s)
// plus the 7/30/90/365-day Chart.js views from the project spec section
// 11: per-zone traffic stacked by direction + parked, hourly-of-day
// distribution, and (when enabled) crowd-estimate and environment charts
// over the same range, sourced from stats.php.
ini_set('display_errors', '0');
?>
<!-- Chart.js — local copy installed with the plugin; no CDN dependency -->
<script src="plugin.php?plugin=fpp-tally&file=js/chart.umd.min.js&nopage=1"
        onerror="console.error('[Tally] chart.umd.min.js not found — reinstall/update the plugin to restore it.');"></script>
<style>
.tally-badge { padding: .25rem .6rem; border-radius: .3rem; font-size: .8rem; font-weight: 600; }
.tally-badge-on { background: #1e7e34; color: #fff; }
.tally-badge-off { background: #6c757d; color: #fff; }
.tally-card { border: 1px solid rgba(255,255,255,0.12); border-radius: .4rem; padding: 1rem; margin-bottom: 1rem; }
.tally-metric { font-size: 1.8rem; font-weight: 700; line-height: 1; }
.tally-metric-label { font-size: .8rem; color: #999; margin-top: 2px; }
.tally-range-btn { background: transparent; border: 1px solid #555; color: #ccc; border-radius: .3rem; padding: .3rem .8rem; font-size: .85rem; cursor: pointer; }
.tally-range-btn.active { border-color: #36a2eb; color: #36a2eb; font-weight: 700; }
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

<!-- ── Range selector ──────────────────────────────────────────────── -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <span class="text-muted small me-1">History:</span>
  <button class="tally-range-btn" data-days="7" onclick="tallySetRange(7)">7 Days</button>
  <button class="tally-range-btn active" data-days="30" onclick="tallySetRange(30)">30 Days</button>
  <button class="tally-range-btn" data-days="90" onclick="tallySetRange(90)">90 Days</button>
  <button class="tally-range-btn" data-days="365" onclick="tallySetRange(365)">365 Days</button>
</div>

<div id="tallyZoneCharts"></div>

<div class="tally-card">
  <h4><i class="fas fa-fw fa-clock"></i> Traffic by Hour of Day</h4>
  <div style="position:relative;height:220px;">
    <canvas id="tallyHourlyChart"></canvas>
  </div>
</div>

<div class="tally-card" id="tallyCrowdChartCard" style="display:none;">
  <h4><i class="fas fa-fw fa-user-group"></i> Estimated Devices Nearby Over Time</h4>
  <div style="position:relative;height:220px;">
    <canvas id="tallyCrowdChart"></canvas>
  </div>
</div>

<div class="tally-card" id="tallyEnvChartCard" style="display:none;">
  <h4><i class="fas fa-fw fa-cloud-sun"></i> Environment Over Time</h4>
  <p class="text-muted small">Shown alongside traffic history above for correlation (e.g. colder nights improving thermal contrast).</p>
  <div style="position:relative;height:220px;">
    <canvas id="tallyEnvChart"></canvas>
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

// ── Live counters / recent events (status.php, 15s poll) ─────────────────

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

async function tallyRefreshLive() {
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
    console.error('[Tally] live refresh failed:', e);
  }
}

// ── Range charts (stats.php, on range change) ─────────────────────────────

Chart.defaults.color = '#bbb';
Chart.defaults.borderColor = 'rgba(255,255,255,0.08)';

let tallyRange = 30;
let tallyZoneChartInstances = {};
let tallyHourlyChartInstance = null;
let tallyCrowdChartInstance = null;
let tallyEnvChartInstance = null;

function tallyFmtDateLabel(raw, days) {
  const d = new Date(raw + 'T00:00:00');
  if (days > 90) return d.toLocaleDateString('en-US', { month: 'short', year: '2-digit' });
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function tallyBuildZoneCharts(zones, days) {
  const box = document.getElementById('tallyZoneCharts');
  const keys = Object.keys(zones || {});
  if (keys.length === 0) { box.innerHTML = ''; return; }

  // Rebuild the canvases only when the zone set actually changes (Chart.js
  // instances are keyed by zone below and reused across range changes/live
  // updates -- destroying and recreating every poll would flash the chart).
  const existingKeys = Object.keys(tallyZoneChartInstances);
  const sameSet = existingKeys.length === keys.length && keys.every(k => existingKeys.includes(k));
  if (!sameSet) {
    box.innerHTML = keys.map(k => `<div class="tally-card">
      <h4><i class="fas fa-fw fa-chart-column"></i> ${tallyEsc(zones[k].label)} Traffic</h4>
      <div style="position:relative;height:240px;">
        <canvas id="tallyZoneChart_${tallyEsc(k)}"></canvas>
      </div>
    </div>`).join('');
    Object.values(tallyZoneChartInstances).forEach(c => c.destroy());
    tallyZoneChartInstances = {};
  }

  keys.forEach(k => {
    const z = zones[k];
    const labels = z.chart.labels.map(l => tallyFmtDateLabel(l, days));
    const existing = tallyZoneChartInstances[k];
    if (existing) {
      existing.data.labels = labels;
      existing.data.datasets[0].data = z.chart.direction_a;
      existing.data.datasets[1].data = z.chart.direction_b;
      existing.data.datasets[2].data = z.chart.parked;
      existing.data.datasets[0].label = z.label_a;
      existing.data.datasets[1].label = z.label_b;
      existing.update('none');
      return;
    }
    const ctx = document.getElementById(`tallyZoneChart_${k}`);
    if (!ctx) return;
    tallyZoneChartInstances[k] = new Chart(ctx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: z.label_a, data: z.chart.direction_a, backgroundColor: 'rgba(75,192,192,0.75)', stack: 'traffic' },
          { label: z.label_b, data: z.chart.direction_b, backgroundColor: 'rgba(255,206,86,0.75)', stack: 'traffic' },
          { label: 'Parked', data: z.chart.parked, backgroundColor: 'rgba(255,99,132,0.75)', stack: 'traffic' },
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { boxWidth: 14 } } },
        scales: {
          x: { stacked: true, ticks: { maxTicksLimit: 16, maxRotation: 45 } },
          y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
        },
      },
    });
  });
}

function tallyBuildHourlyChart(hourly) {
  const labels = Object.keys(hourly || {});
  const data = Object.values(hourly || {});
  if (tallyHourlyChartInstance) {
    tallyHourlyChartInstance.data.labels = labels;
    tallyHourlyChartInstance.data.datasets[0].data = data;
    tallyHourlyChartInstance.update('none');
    return;
  }
  tallyHourlyChartInstance = new Chart(document.getElementById('tallyHourlyChart'), {
    type: 'bar',
    data: { labels, datasets: [{ label: 'Vehicle Passes', data, backgroundColor: 'rgba(54,162,235,0.75)' }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });
}

function tallyBuildCrowdChart(crowd) {
  const card = document.getElementById('tallyCrowdChartCard');
  if (!crowd || !crowd.labels || crowd.labels.length === 0) { card.style.display = 'none'; return; }
  card.style.display = '';
  const labels = crowd.labels.map(l => (l || '').slice(0, 16).replace('T', ' '));
  if (tallyCrowdChartInstance) {
    tallyCrowdChartInstance.data.labels = labels;
    tallyCrowdChartInstance.data.datasets[0].data = crowd.data;
    tallyCrowdChartInstance.update('none');
    return;
  }
  tallyCrowdChartInstance = new Chart(document.getElementById('tallyCrowdChart'), {
    type: 'line',
    data: { labels, datasets: [{ label: 'Estimated Devices', data: crowd.data, borderColor: 'rgba(153,102,255,1)', backgroundColor: 'rgba(153,102,255,0.15)', fill: true, tension: 0.3, pointRadius: labels.length > 60 ? 0 : 2 }] },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { x: { ticks: { maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });
}

function tallyBuildEnvChart(env) {
  const card = document.getElementById('tallyEnvChartCard');
  if (!env || !env.labels || env.labels.length === 0) { card.style.display = 'none'; return; }
  card.style.display = '';
  const labels = env.labels.map(l => (l || '').slice(0, 16).replace('T', ' '));
  if (tallyEnvChartInstance) {
    tallyEnvChartInstance.data.labels = labels;
    tallyEnvChartInstance.data.datasets[0].data = env.temperature_f;
    tallyEnvChartInstance.data.datasets[1].data = env.humidity_pct;
    tallyEnvChartInstance.update('none');
    return;
  }
  tallyEnvChartInstance = new Chart(document.getElementById('tallyEnvChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Temperature (°F)', data: env.temperature_f, borderColor: 'rgba(255,99,132,1)', backgroundColor: 'rgba(255,99,132,0.1)', yAxisID: 'yTemp', tension: 0.3, pointRadius: labels.length > 60 ? 0 : 2 },
        { label: 'Humidity (%)', data: env.humidity_pct, borderColor: 'rgba(75,192,192,1)', backgroundColor: 'rgba(75,192,192,0.1)', yAxisID: 'yHum', tension: 0.3, pointRadius: labels.length > 60 ? 0 : 2 },
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { labels: { boxWidth: 14 } } },
      scales: {
        x: { ticks: { maxTicksLimit: 10 } },
        yTemp: { position: 'left', title: { display: true, text: '°F' } },
        yHum:  { position: 'right', title: { display: true, text: '%' }, grid: { drawOnChartArea: false }, min: 0, max: 100 },
      },
    },
  });
}

async function tallyRefreshStats() {
  try {
    const res = await fetch(`plugin.php?plugin=fpp-tally&page=www/stats.php&nopage=1&days=${tallyRange}`, { cache: 'no-store' });
    const data = await res.json();
    if (data.error) throw new Error(data.error);

    tallyBuildZoneCharts(data.zones, data.days);
    tallyBuildHourlyChart(data.hourly);
    tallyBuildCrowdChart(data.crowd);
    tallyBuildEnvChart(data.environment);
  } catch (e) {
    console.error('[Tally] stats refresh failed:', e);
  }
}

function tallySetRange(days) {
  tallyRange = days;
  document.querySelectorAll('.tally-range-btn').forEach(btn => {
    btn.classList.toggle('active', parseInt(btn.dataset.days, 10) === days);
  });
  tallyRefreshStats();
}

// ── Init ────────────────────────────────────────────────────────────────
tallyRefreshLive();
tallyRefreshStats();
setInterval(tallyRefreshLive, 15000);
setInterval(tallyRefreshStats, 60000);
</script>
