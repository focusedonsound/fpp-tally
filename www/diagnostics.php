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
.diag-gatebar-track { flex: 1; height: 14px; background: rgba(255,255,255,0.08); border-radius: 3px; overflow: hidden; position: relative; }
.diag-gatebar-fill { height: 100%; border-radius: 3px; transition: width .2s ease; }
.diag-gatebar-fill.over { box-shadow: 0 0 0 1px #fff inset; }
.diag-threshold-line { position: absolute; top: -2px; bottom: -2px; width: 2px; background: #fff; opacity: .85; pointer-events: none; }
.diag-threshold-box { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.1); border-radius: .3rem; padding: .6rem .75rem; }
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
.diag-lane-road {
  position: relative; height: 130px; border-radius: .4rem; overflow: hidden;
  border: 2px solid rgba(255,255,255,0.15);
  /* Asphalt: flat dark base + a faint speckle texture so it doesn't
     read as a plain gray box, plus solid white edge lines top/bottom
     (the shoulder markings a real 2-lane road has) and the road's own
     halves painted underneath everything else. */
  background-color: #2e2f31;
  background-image:
    radial-gradient(rgba(255,255,255,0.05) 1px, transparent 1px),
    radial-gradient(rgba(0,0,0,0.25) 1px, transparent 1px);
  background-size: 9px 9px, 13px 13px;
  background-position: 0 0, 4px 6px;
  box-shadow: inset 0 6px 0 -3px rgba(255,255,255,0.55), inset 0 -6px 0 -3px rgba(255,255,255,0.55);
}
.diag-lane-half { position: absolute; top: 0; bottom: 0; transition: background-color .2s ease; display: flex; align-items: flex-end; justify-content: center; padding-bottom: .4rem; z-index: 1; }
.diag-lane-half.occupied { background-color: rgba(54,162,235,0.28); }
.diag-lane-half .diag-lane-status { font-size: .75rem; font-weight: 700; color: #cfe8ff; text-shadow: 0 1px 2px rgba(0,0,0,0.6); }
.diag-lane-near { left: 0; }
.diag-lane-far { right: 0; }
.diag-lane-divider {
  position: absolute; top: 6px; bottom: 6px; width: 0;
  border-left: 3px dashed #f0c419; opacity: .95; z-index: 2;
  transition: left .2s ease;
}
.diag-lane-mailbox {
  position: absolute; left: 6px; top: 50%; transform: translateY(-50%);
  font-size: 1.5rem; z-index: 3; filter: drop-shadow(0 1px 2px rgba(0,0,0,.6));
}
.diag-lane-car {
  position: absolute; top: 50%; font-size: 1.7rem; z-index: 4;
  transform: translate(-50%, -50%); transition: left .5s ease, opacity .3s ease;
  filter: drop-shadow(0 1px 2px rgba(0,0,0,.6));
}
.diag-lane-labelrow { display: flex; justify-content: space-between; font-size: .7rem; color: #888; margin-top: .35rem; text-transform: uppercase; letter-spacing: .04em; }
.diag-lane-gates { display: flex; gap: 2px; margin-top: .75rem; }
.diag-lane-gate { width: 100%; height: 10px; border-radius: 2px; background: rgba(255,255,255,0.1); }
.diag-lastpass { font-size: .85rem; margin-top: .6rem; }
.diag-gatesens-table { width: 100%; font-size: .85rem; border-collapse: collapse; }
.diag-gatesens-table th { text-align: left; font-weight: 600; color: #999; padding: .3rem .5rem; border-bottom: 1px solid rgba(255,255,255,0.15); }
.diag-gatesens-table td { padding: .25rem .5rem; border-bottom: 1px solid rgba(255,255,255,0.06); vertical-align: middle; }
.diag-gatesens-table input { width: 5rem; }
.diag-zone-tabs { display: flex; gap: .4rem; margin-bottom: .75rem; }
.diag-zone-tab { padding: .3rem .8rem; border-radius: .3rem; border: 1px solid rgba(255,255,255,0.15); background: rgba(255,255,255,0.04); color: inherit; cursor: pointer; font-size: .85rem; }
.diag-zone-tab.active { background: #36a2eb; border-color: #36a2eb; color: #fff; font-weight: 600; }
.diag-entrance-lane {
  position: relative; height: 90px; border-radius: .4rem; overflow: hidden;
  border: 2px solid rgba(255,255,255,0.15);
  background-color: #2e2f31;
  background-image: radial-gradient(rgba(255,255,255,0.05) 1px, transparent 1px), radial-gradient(rgba(0,0,0,0.25) 1px, transparent 1px);
  background-size: 9px 9px, 13px 13px;
  background-position: 0 0, 4px 6px;
}
.diag-entrance-marker { position: absolute; top: 50%; font-size: 1.7rem; transform: translate(-50%, -50%); transition: left .3s ease, opacity .3s ease; filter: drop-shadow(0 1px 2px rgba(0,0,0,.6)); }
.diag-entrance-labelrow { display: flex; justify-content: space-between; font-size: .7rem; color: #888; margin-top: .35rem; text-transform: uppercase; letter-spacing: .04em; }
.diag-hlk-section { border: 1px dashed rgba(255,255,255,0.15); border-radius: .5rem; padding: 1rem; margin-bottom: 1.5rem; }
.diag-hlk-section > h3 { font-size: 1rem; color: #999; margin-bottom: .75rem; text-transform: uppercase; letter-spacing: .04em; }
.diag-collapsible-header { cursor: pointer; display: flex; align-items: center; justify-content: space-between; user-select: none; }
.diag-collapsible-header .diag-chevron { transition: transform .15s ease; color: #999; font-size: .85em; }
.diag-collapsible-header.collapsed .diag-chevron { transform: rotate(-90deg); }
.diag-collapsible-body { overflow: hidden; }
.diag-collapsible-body.collapsed { display: none; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h3 class="mb-0"><i class="fas fa-fw fa-satellite-dish"></i> Tally Diagnostics</h3>
  <span class="text-muted small">Live view only — nothing on this page is written to the database.</span>
</div>

<div class="tally-card" id="diagLaneCard">
  <h4 class="diag-collapsible-header" id="hdr-lane" onclick="diagToggleSection('lane')">
    <span><i class="fas fa-fw fa-road"></i> Lane View</span>
    <i class="fas fa-chevron-down diag-chevron"></i>
  </h4>
  <div id="body-lane" class="diag-collapsible-body">
  <div class="diag-zone-tabs">
    <button type="button" class="diag-zone-tab active" id="diagZoneTabDriveway" onclick="diagSetZoneTab('driveway')">Driveway (Radar)</button>
    <button type="button" class="diag-zone-tab" id="diagZoneTabEntrance" onclick="diagSetZoneTab('entrance')">Entrance (Thermal)</button>
  </div>

  <div id="diagZoneDriveway">
    <p class="text-muted small mb-2">
      Combined read from both radars (they're co-located, so whichever side currently sees a
      stronger signal in a gate wins). The near lane is closer to the sensors — typically traffic
      leaving the property; the far lane is the far side of the road — typically incoming traffic.
      This is a display split only, not used for detection or triggers.
    </p>
    <div class="diag-lane-road" id="diagLaneRoad">
      <div class="diag-lane-mailbox" title="Mailbox / sensor position">📫</div>
      <div class="diag-lane-divider" id="diagLaneDivider"></div>
      <div class="diag-lane-half diag-lane-near" id="diagLaneNear">
        <span class="diag-lane-status" id="diagLaneNearStatus"></span>
      </div>
      <div class="diag-lane-half diag-lane-far" id="diagLaneFar">
        <span class="diag-lane-status" id="diagLaneFarStatus"></span>
      </div>
      <div class="diag-lane-car" id="diagLaneCar" style="left:0%; opacity:0;">🚗</div>
    </div>
    <div class="diag-lane-labelrow">
      <span>Near lane — leaving</span>
      <span>Far lane — incoming</span>
    </div>
    <div class="diag-lane-gates" id="diagLaneGates"></div>
    <div class="d-flex align-items-center gap-2 flex-wrap mt-2">
      <label class="small text-muted mb-0" for="diagLaneSplit">Near/far split (gate)</label>
      <input type="number" id="diagLaneSplit" class="form-control form-control-sm" style="width:5rem;" min="1" max="8" value="4" oninput="diagRedrawGateBars()">
      <button type="button" class="tally-btn tally-btn-sm" onclick="diagSaveLaneSplit()">
        <i class="fas fa-floppy-disk"></i> Save &amp; Apply
      </button>
      <span class="small" id="diagLaneSplitStatus"></span>
    </div>
    <div class="diag-lastpass text-muted" id="diagLastPass">No pass recorded yet this session.</div>
  </div>

  <div id="diagZoneEntrance" style="display:none;">
    <p class="text-muted small mb-2">
      Single-lane read from the thermal camera's tracked heat signature position across the sensor's
      32-column field of view. Direction is inferred live from which way that position is
      trending between polls, not a logged pass history — see the Thermal card below for the raw
      delta grid this comes from.
    </p>
    <div class="diag-entrance-lane" id="diagEntranceLane">
      <div class="diag-entrance-marker" id="diagEntranceMarker" style="left:50%; opacity:0;">🚶</div>
    </div>
    <div class="diag-entrance-labelrow">
      <span id="diagEntranceLabelA">Inbound</span>
      <span id="diagEntranceLabelB">Outbound</span>
    </div>
    <div class="text-muted small mt-2" id="diagEntranceStatus">No thermal target currently tracked.</div>
  </div>
  </div>
</div>

<div class="diag-hlk-section">
  <h3 class="diag-collapsible-header" id="hdr-hlk" onclick="diagToggleSection('hlk')">
    <span><i class="fas fa-fw fa-satellite-dish"></i> HLK Radar (LD2410B) — Detection &amp; Tuning</span>
    <i class="fas fa-chevron-down diag-chevron"></i>
  </h3>
  <div id="body-hlk" class="diag-collapsible-body">

  <div class="tally-card" id="diagLd2410Card">
    <h4><i class="fas fa-fw fa-satellite-dish"></i> Per-Gate Readout</h4>
    <div class="diag-legend">
      <span class="sw" style="background:#36a2eb;"></span> Moving energy
      &nbsp;&nbsp;<span class="sw" style="background:#ff9f40;"></span> Static energy
      &nbsp;&nbsp;each gate ≈ 0.75&nbsp;m, gate 0 nearest the sensor
    </div>
    <div class="diag-threshold-box mb-3">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <label class="small text-muted mb-0" for="diagMinEnergy">Detection threshold (min_energy)</label>
        <input type="number" id="diagMinEnergy" class="form-control form-control-sm" style="width:6rem;" min="0" max="500" value="20" oninput="diagRedrawGateBars()">
        <button type="button" class="tally-btn tally-btn-sm" onclick="diagSaveMinEnergy()">
          <i class="fas fa-floppy-disk"></i> Save &amp; Apply
        </button>
        <span class="small" id="diagMinEnergyStatus"></span>
      </div>
      <div class="text-muted small mt-1">
        The dashed line on each bar is this threshold. A gate crossing it (highlighted) is what Tally's own
        software applies on top of whichever gate reads highest, in addition to (not instead of) the radar's
        own native per-gate sensitivity below. Adjusting the number updates the line immediately so you can
        see the effect before saving; <strong>Save &amp; Apply</strong> writes it to the config and restarts
        the daemon so real detection actually uses it.
      </div>
    </div>
    <div id="diagLd2410Body" class="row g-4">
      <div class="col-12 text-muted small">Loading…</div>
    </div>
  </div>

  <div class="tally-card" id="diagGateSensCard">
    <h4><i class="fas fa-fw fa-sliders"></i> Per-Gate Sensitivity (native radar filtering)</h4>
    <p class="text-muted small mb-2">
      This is the same per-gate sensitivity the official HLK config tool exposes — a real setting written to
      the radar's own memory (persists across power cycles), applied by the radar itself before Tally ever sees
      the data. Higher sensitivity number = <strong>less</strong> sensitive (a gate's energy has to clear that
      number before the radar reports a target there at all) — useful for silencing a specific gate that keeps
      picking up wind-blown branches or a neighbor's fixture, without dialing back detection everywhere.
    </p>
    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
      <label class="small text-muted mb-0">Side</label>
      <select id="diagGateSensSide" class="form-control form-control-sm" style="width:6rem;" onchange="diagGateSensClear()">
        <option value="A">A</option>
        <option value="B">B</option>
      </select>
      <button type="button" class="tally-btn tally-btn-sm" onclick="diagReadGateSens()">
        <i class="fas fa-arrows-rotate"></i> Read Current Values
      </button>
      <span class="small" id="diagGateSensStatus"></span>
    </div>
    <div id="diagGateSensBody" class="text-muted small">Click "Read Current Values" to load this side's current per-gate sensitivity from the radar.</div>
  </div>

  <div class="tally-card mb-0" id="diagCamTuneCard">
    <h4><i class="fas fa-fw fa-car"></i> Camera-Assisted Tuning</h4>
    <p class="text-muted small mb-2">
      When the camera module is enabled, every radar pass gets a best-effort camera classification
      (car/truck/bus vs. person/dog/cat/...) paired with that pass's peak gate energy. Once enough of both
      kinds have been seen, this suggests a <code>min_energy</code> threshold that would have kept every
      vehicle observed while excluding everything else — it's only a suggestion; nothing here changes
      detection until you press Apply.
    </p>
    <div id="diagCamTuneDisabled" class="text-muted small" style="display:none;">
      Camera module not enabled in Setup — enable it to start collecting samples.
    </div>
    <div id="diagCamTuneBody" style="display:none;">
      <div class="small text-muted mb-2" id="diagCamTuneLast">No classification yet this session.</div>
      <div class="d-flex flex-wrap gap-4 mb-2">
        <div>
          <div class="small text-muted">Vehicle samples</div>
          <div id="diagCamTuneVehicleStats">—</div>
        </div>
        <div>
          <div class="small text-muted">Non-vehicle samples</div>
          <div id="diagCamTuneNonVehicleStats">—</div>
        </div>
      </div>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="small" id="diagCamTuneSuggestion">Collecting data…</span>
        <button type="button" class="tally-btn tally-btn-sm" id="diagCamTuneApplyBtn" onclick="diagCamTuneApply()" disabled>
          <i class="fas fa-check"></i> Apply Suggested Threshold
        </button>
        <span class="small" id="diagCamTuneApplyStatus"></span>
      </div>
    </div>
  </div>

  </div>
</div>

<div class="row">
  <div class="col-lg-5">
    <div class="tally-card" id="diagCamCard">
      <h4 class="diag-collapsible-header" id="hdr-cam" onclick="diagToggleSection('cam')">
        <span><i class="fas fa-fw fa-camera"></i> Camera Reference</span>
        <i class="fas fa-chevron-down diag-chevron"></i>
      </h4>
      <div id="body-cam" class="diag-collapsible-body">
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
      <hr class="my-3" style="border-color: rgba(255,255,255,0.1);">
      <h4 class="mb-2"><i class="fas fa-fw fa-sliders"></i> White Balance</h4>
      <div class="diag-threshold-box">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <label class="small text-muted mb-0" for="diagCamAwbMode">Mode</label>
          <select id="diagCamAwbMode" class="form-control form-control-sm" style="width:9rem;" onchange="diagCamAwbModeChanged()">
            <option value="auto">Auto</option>
            <option value="daylight">Daylight</option>
            <option value="cloudy">Cloudy</option>
            <option value="indoor">Indoor</option>
            <option value="tungsten">Tungsten</option>
            <option value="incandescent">Incandescent</option>
            <option value="fluorescent">Fluorescent</option>
            <option value="custom">Custom gains</option>
          </select>
          <span id="diagCamAwbGainsWrap" style="display:none;">
            <label class="small text-muted mb-0" for="diagCamAwbRed">R</label>
            <input type="number" id="diagCamAwbRed" class="form-control form-control-sm" style="width:4.5rem;" step="0.05" min="0.1" max="8" placeholder="1.5">
            <label class="small text-muted mb-0" for="diagCamAwbBlue">B</label>
            <input type="number" id="diagCamAwbBlue" class="form-control form-control-sm" style="width:4.5rem;" step="0.05" min="0.1" max="8" placeholder="1.2">
          </span>
          <button type="button" class="tally-btn tally-btn-sm" onclick="diagSaveCameraAwb()">
            <i class="fas fa-floppy-disk"></i> Save &amp; Apply
          </button>
          <span class="small" id="diagCamAwbStatus"></span>
        </div>
        <div class="text-muted small mt-1" id="diagCamAwbNote">
          Only affects the CSI/Pi Camera Module path (rpicam's own AWB control) — a USB webcam ignores this.
          "Custom gains" disables auto white balance entirely in favor of fixed red/blue multipliers
          (rpicam-still/-vid's <code>--awbgains</code>); higher red = warmer, higher blue = cooler.
        </div>
      </div>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="tally-card" id="diagThermalCard">
      <h4 class="diag-collapsible-header" id="hdr-thermal" onclick="diagToggleSection('thermal')">
        <span><i class="fas fa-fw fa-fire"></i> MLX90640 Thermal — Delta Grid &amp; Tuning</span>
        <i class="fas fa-chevron-down diag-chevron"></i>
      </h4>
      <div id="body-thermal" class="diag-collapsible-body">
      <div id="diagThermalBody">
        <div class="text-muted small">Loading…</div>
      </div>
      <hr class="my-3" style="border-color: rgba(255,255,255,0.1);">
      <h4 class="mb-2"><i class="fas fa-fw fa-sliders"></i> Thermal Tuning</h4>
      <div id="diagThermalTuneDisabled" class="text-muted small" style="display:none;">
        Thermal module not enabled in Setup.
      </div>
      <div id="diagThermalTuneBody" class="diag-threshold-box" style="display:none;">
        <div class="row g-2">
          <div class="col-sm-6">
            <label class="small text-muted mb-0" for="diagThDeltaThreshold">Foreground threshold (°C)</label>
            <div class="d-flex align-items-center gap-1">
              <input type="number" id="diagThDeltaThreshold" class="form-control form-control-sm" step="0.1" min="0.1" max="20">
              <button type="button" class="tally-btn tally-btn-sm" onclick="diagSaveThermalParam('delta_threshold_c', document.getElementById('diagThDeltaThreshold').value)"><i class="fas fa-floppy-disk"></i></button>
            </div>
          </div>
          <div class="col-sm-6">
            <label class="small text-muted mb-0" for="diagThMinBlob">Min blob size (px)</label>
            <div class="d-flex align-items-center gap-1">
              <input type="number" id="diagThMinBlob" class="form-control form-control-sm" step="1" min="1" max="200">
              <button type="button" class="tally-btn tally-btn-sm" onclick="diagSaveThermalParam('min_blob_size', document.getElementById('diagThMinBlob').value)"><i class="fas fa-floppy-disk"></i></button>
            </div>
          </div>
          <div class="col-sm-6">
            <label class="small text-muted mb-0" for="diagThMinTravel">Min travel to log a pass (cols)</label>
            <div class="d-flex align-items-center gap-1">
              <input type="number" id="diagThMinTravel" class="form-control form-control-sm" step="0.5" min="0.5" max="32">
              <button type="button" class="tally-btn tally-btn-sm" onclick="diagSaveThermalParam('min_travel_cols', document.getElementById('diagThMinTravel').value)"><i class="fas fa-floppy-disk"></i></button>
            </div>
          </div>
          <div class="col-sm-6">
            <label class="small text-muted mb-0" for="diagThParked">Parked timeout (s)</label>
            <div class="d-flex align-items-center gap-1">
              <input type="number" id="diagThParked" class="form-control form-control-sm" step="1" min="5" max="3600">
              <button type="button" class="tally-btn tally-btn-sm" onclick="diagSaveThermalParam('parked_timeout_s', document.getElementById('diagThParked').value)"><i class="fas fa-floppy-disk"></i></button>
            </div>
          </div>
          <div class="col-sm-6">
            <label class="small text-muted mb-0" for="diagThMountDist">Mount distance (m)</label>
            <div class="d-flex align-items-center gap-1">
              <input type="number" id="diagThMountDist" class="form-control form-control-sm" step="0.1" min="0.3" max="50">
              <button type="button" class="tally-btn tally-btn-sm" onclick="diagSaveThermalParam('mount_distance_m', document.getElementById('diagThMountDist').value)"><i class="fas fa-floppy-disk"></i></button>
            </div>
          </div>
          <div class="col-sm-6 d-flex align-items-end">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="diagThFlip" onchange="diagSaveThermalParam('flip_direction', document.getElementById('diagThFlip').checked)">
              <label class="form-check-label small text-muted" for="diagThFlip">Flip direction labels</label>
            </div>
          </div>
        </div>
        <div class="text-muted small mt-2" id="diagThermalTuneStatus">Each field saves and restarts the daemon on its own — no separate Apply step.</div>
      </div>
      </div>
    </div>
  </div>
</div>

<div class="tally-card" id="diagBleCard">
  <h4 class="diag-collapsible-header" id="hdr-ble" onclick="diagToggleSection('ble')">
    <span><i class="fas fa-fw fa-bluetooth-b"></i> Raw BLE Scan</span>
    <i class="fas fa-chevron-down diag-chevron"></i>
  </h4>
  <div id="body-ble" class="diag-collapsible-body">
  <div id="diagBleBody" class="text-muted small">Loading…</div>
  </div>
</div>

<div class="tally-card" id="diagWifiCard">
  <h4 class="diag-collapsible-header" id="hdr-wifi" onclick="diagToggleSection('wifi')">
    <span><i class="fas fa-fw fa-wifi"></i> Raw WiFi Probe-Request Scan</span>
    <i class="fas fa-chevron-down diag-chevron"></i>
  </h4>
  <div id="body-wifi" class="diag-collapsible-body">
  <div id="diagWifiBody" class="text-muted small">Loading…</div>
  </div>
</div>

<script>
const DIAG_NUM_GATES = 9;

function diagBadge(text, cls) {
  return `<span class="tally-badge ${cls}">${text}</span>`;
}

// BLE device names and WiFi probed-SSID strings are free text taken
// straight off the air from whatever's broadcasting nearby - not
// something Tally controls or validates, so they're escaped before ever
// landing in innerHTML. Without this, a spoofed BLE name or SSID
// containing markup would execute in the browser of whoever has this
// page open.
function diagEsc(s) {
  const d = document.createElement('div');
  d.textContent = s == null ? '' : String(s);
  return d.innerHTML;
}

// Visual scale ceiling for the bars. Raw gate energy isn't capped at
// 100 the way the aggregate move/static energy fields are (confirmed on
// real hardware: individual gates commonly read 100-200+ right next to
// a real target) -- clamping the bar width at a 0-100 scale made most
// real signals plateau at full-width and made typical low/idle readings
// (0-30, the common case with nothing in the zone) only a few pixels
// wide, which is why the bars looked like they "weren't showing
// anything." 200 gives both ends of that real range somewhere to go.
const DIAG_GATE_SCALE_MAX = 200;
function diagGatePct(energy) {
  return Math.max(0, Math.min(100, (energy / DIAG_GATE_SCALE_MAX) * 100));
}

function diagGateBars(side, sideData, threshold) {
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
    // Negotiated engineering mode at connect but the most recent report
    // still decoded as basic-format -- worth calling out distinctly from
    // "never negotiated it at all," since it usually means a marginal
    // connection (noisy/loose wiring) rather than a config problem.
    if (sideData.engineering_negotiated) {
      html += `<div class="text-muted small mb-1">⚠️ Negotiated engineering mode at connect, but the most recent report came back in basic format — per-gate detail unavailable right now. Often a marginal serial connection (check the cable/wiring on this side) rather than a config issue.</div>`;
    } else {
      html += `<div class="text-muted small mb-1">Per-gate detail unavailable — this unit didn't accept engineering mode at connect.</div>`;
    }
    html += `<div class="small">Move energy: <strong>${moveE}</strong> &nbsp; Static energy: <strong>${staticE}</strong></div>`;
    if (sideData.detect_dist_cm != null) {
      html += `<div class="small text-muted">Detected distance: ${sideData.detect_dist_cm} cm</div>`;
    }
    return html;
  }

  const thresholdLine = `<span class="diag-threshold-line" style="left:${diagGatePct(threshold)}%"></span>`;
  for (let g = 0; g < DIAG_NUM_GATES; g++) {
    const moveE = sideData.gate_move_energy[g] ?? 0;
    const staticE = sideData.gate_static_energy[g] ?? 0;
    const isMaxMove = g === sideData.max_move_gate;
    const isMaxStatic = g === sideData.max_static_gate;
    const moveOver = moveE >= threshold;
    const staticOver = staticE >= threshold;
    html += `<div class="diag-gatebar-row">
      <span class="diag-gatebar-label">G${g}${isMaxMove ? ' •' : ''}</span>
      <span class="diag-gatebar-track"><span class="diag-gatebar-fill move${moveOver ? ' over' : ''}" style="width:${diagGatePct(moveE)}%"></span>${thresholdLine}</span>
      <span class="diag-gatebar-val">${moveE}</span>
    </div>
    <div class="diag-gatebar-row">
      <span class="diag-gatebar-label">${isMaxStatic ? ' •' : ''}</span>
      <span class="diag-gatebar-track"><span class="diag-gatebar-fill static${staticOver ? ' over' : ''}" style="width:${diagGatePct(staticE)}%"></span>${thresholdLine}</span>
      <span class="diag-gatebar-val">${staticE}</span>
    </div>`;
  }
  return html;
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
      <td>${d.name ? diagEsc(d.name) : '<span class="text-muted">—</span>'}</td>
      <td>${(d.vendors && d.vendors.length) ? d.vendors.join(', ') : '<span class="text-muted">—</span>'}</td>
      <td>${d.rssi != null ? d.rssi + ' dBm' : '—'}</td>
      <td>${diagRelTime(d.first_seen)}</td>
      <td>${diagRelTime(d.last_seen)}</td>
    </tr>`;
  }
  html += '</tbody></table></div>';
  bodyEl.innerHTML = html;
}

function diagWifiTable(bodyEl, data, moduleEnabled, offlineNote) {
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
  const windowMin = data.rolling_window_s ? Math.round(data.rolling_window_s / 60) : null;
  let html = `<div class="d-flex align-items-center gap-2 mb-2">
    <span class="diag-addr-count">${devices.length}</span>
    <span class="text-muted small">unique device${devices.length === 1 ? '' : 's'} seen in the last${windowMin ? ' ' + windowMin + ' min' : ' scan window'}</span>
    ${stale ? diagBadge('Stale', 'tally-badge-stale') : ''}
  </div>`;
  if (offlineNote) html += `<div class="text-muted small mb-2">${offlineNote}</div>`;

  if (devices.length === 0) {
    html += `<div class="text-muted small">No devices seen recently.</div>`;
    bodyEl.innerHTML = html;
    return;
  }

  html += `<div style="overflow-x:auto;"><table class="diag-ble-table">
    <thead><tr>
      <th>Address</th><th>Type</th><th>Vendor</th><th>Probed SSID(s)</th><th>RSSI</th><th>Channel</th><th>First seen</th><th>Last seen</th>
    </tr></thead><tbody>`;
  for (const d of devices) {
    const ssids = (d.probed_ssids && d.probed_ssids.length)
      ? d.probed_ssids.map(s => `<span class="mono">${diagEsc(s)}</span>`).join(', ')
      : '<span class="text-muted">— (wildcard/hidden)</span>';
    html += `<tr>
      <td class="mono">${d.address}</td>
      <td>${d.randomized ? 'Randomized' : 'Fixed'}</td>
      <td>${d.vendor ? diagEsc(d.vendor) : '<span class="text-muted">—</span>'}</td>
      <td>${ssids}</td>
      <td>${d.rssi != null ? d.rssi + ' dBm' : '—'}</td>
      <td>${d.channel != null ? d.channel : '—'}</td>
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
    // "blob" is the correct computer-vision term for the underlying
    // connected-component detection (see thermal.py's find_blobs) and
    // stays that way in code/config field names, but showing it verbatim
    // on a page a real person might be looking at themselves on (this is
    // a body-heat tracker, after all) reads as needlessly clinical --
    // "heat signature" says the same thing without the "you are an
    // object" framing.
    const blobCount = (data.blobs || []).length;
    meta.innerHTML = `${blobCount} heat signature${blobCount === 1 ? '' : 's'} detected${data.tracking ? ` — tracking (dwell ${data.track_dwell_s ?? 0}s)` : ''} ${stale}`;
  }
}

// --- Collapsible sections -------------------------------------------------
// Persisted per-browser via localStorage so a collapsed section (e.g. HLK
// radar tuning on a build that only uses the thermal camera) stays
// collapsed across reloads instead of resetting every visit. Sections not
// yet in localStorage default to expanded, except the ones listed in
// DIAG_DEFAULT_COLLAPSED -- this build's stated intent is thermal+camera
// only, with the HLK/BLE/WiFi sections kept as optional/unused extras, so
// those start collapsed on a first-ever visit rather than expanded.
const DIAG_DEFAULT_COLLAPSED = ['hlk', 'ble', 'wifi'];

function diagSectionCollapsed(id) {
  try {
    const stored = localStorage.getItem('tally-diag-collapsed-' + id);
    if (stored != null) return stored === '1';
  } catch (e) { /* private mode / blocked storage -- fall through to default */ }
  return DIAG_DEFAULT_COLLAPSED.includes(id);
}

function diagApplyCollapsed(id, collapsed) {
  const header = document.getElementById('hdr-' + id);
  const body = document.getElementById('body-' + id);
  if (!header || !body) return;
  body.classList.toggle('collapsed', collapsed);
  header.classList.toggle('collapsed', collapsed);
}

function diagToggleSection(id) {
  const collapsed = !diagSectionCollapsed(id);
  diagApplyCollapsed(id, collapsed);
  try { localStorage.setItem('tally-diag-collapsed-' + id, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
}

function diagInitCollapsibles() {
  document.querySelectorAll('.diag-collapsible-body').forEach(body => {
    const id = body.id.replace(/^body-/, '');
    diagApplyCollapsed(id, diagSectionCollapsed(id));
  });
}

// --- Zone tabs (Lane View: Driveway/radar vs Entrance/thermal) -----------
let diagActiveZoneTab = 'driveway';
function diagSetZoneTab(zone) {
  diagActiveZoneTab = zone;
  document.getElementById('diagZoneDriveway').style.display = zone === 'driveway' ? '' : 'none';
  document.getElementById('diagZoneEntrance').style.display = zone === 'entrance' ? '' : 'none';
  document.getElementById('diagZoneTabDriveway').classList.toggle('active', zone === 'driveway');
  document.getElementById('diagZoneTabEntrance').classList.toggle('active', zone === 'entrance');
}

// Direction is inferred client-side by comparing this poll's tracked
// column against the previous poll's -- the daemon's live-state file
// only carries the current blob position/dwell, not a velocity or a
// completed-pass log the way ld2410's last_pass is, so there's nothing
// server-side to read a direction from between polls.
let diagEntrancePrevCol = null;
function diagRenderEntranceLane(thermalData, moduleEnabled) {
  const marker = document.getElementById('diagEntranceMarker');
  const statusEl = document.getElementById('diagEntranceStatus');
  if (!moduleEnabled) {
    marker.style.opacity = '0';
    statusEl.textContent = 'Thermal module not enabled in Setup.';
    diagEntrancePrevCol = null;
    return;
  }
  const blobs = (thermalData && thermalData.blobs) || [];
  if (!thermalData || !blobs.length) {
    marker.style.opacity = '0';
    statusEl.textContent = thermalData && thermalData.stale
      ? 'Thermal data is stale — check the daemon/sensor.'
      : 'No thermal target currently tracked.';
    diagEntrancePrevCol = null;
    return;
  }
  const cols = thermalData.cols || 32;
  const col = blobs[0].col;
  const pct = (col / (cols - 1)) * 100;
  marker.style.left = pct + '%';
  marker.style.opacity = '1';

  let trend = '';
  if (diagEntrancePrevCol != null) {
    const d = col - diagEntrancePrevCol;
    if (d > 0.3) trend = ' — moving toward Outbound';
    else if (d < -0.3) trend = ' — moving toward Inbound';
  }
  diagEntrancePrevCol = col;

  const dwell = thermalData.track_dwell_s ?? 0;
  const stale = thermalData.stale ? ' ' + diagBadge('Stale', 'tally-badge-stale') : '';
  statusEl.innerHTML = `Tracking — dwell ${dwell}s${trend}${stale}`;
}

// --- Thermal tuning --------------------------------------------------------
let diagThermalTuneInitDone = false;
function diagInitThermalTune(cfg) {
  if (diagThermalTuneInitDone || !cfg) return;
  diagThermalTuneInitDone = true;
  document.getElementById('diagThDeltaThreshold').value = cfg.delta_threshold_c;
  document.getElementById('diagThMinBlob').value = cfg.min_blob_size;
  document.getElementById('diagThMinTravel').value = cfg.min_travel_cols;
  document.getElementById('diagThParked').value = cfg.parked_timeout_s;
  document.getElementById('diagThMountDist').value = cfg.mount_distance_m;
  document.getElementById('diagThFlip').checked = !!cfg.flip_direction;
}

async function diagSaveThermalParam(field, value) {
  const statusEl = document.getElementById('diagThermalTuneStatus');
  statusEl.textContent = 'Saving…';
  try {
    const fd = new FormData();
    fd.append('action', 'set_thermal_param');
    fd.append('field', field);
    fd.append('value', value);
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_tune.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const data = await res.json();
    if (data.status !== 'OK') {
      statusEl.textContent = 'Error: ' + (data.message || 'save failed');
      return;
    }
    statusEl.textContent = 'Saved — restarting daemon…';
    const fd2 = new FormData();
    fd2.append('action', 'restart');
    await fetch('plugin.php?plugin=fpp-tally&page=www/control.php&nopage=1', { method: 'POST', body: fd2, cache: 'no-store' });
    statusEl.textContent = 'Applied.';
    setTimeout(() => { statusEl.textContent = 'Each field saves and restarts the daemon on its own — no separate Apply step.'; }, 4000);
  } catch (e) {
    statusEl.textContent = 'Request failed.';
  }
}

// --- Camera white balance --------------------------------------------------
let diagCamAwbInitDone = false;

function diagCamAwbModeChanged() {
  const mode = document.getElementById('diagCamAwbMode').value;
  document.getElementById('diagCamAwbGainsWrap').style.display = mode === 'custom' ? '' : 'none';
}

function diagInitCameraAwb(camData) {
  if (diagCamAwbInitDone || !camData) return;
  diagCamAwbInitDone = true;
  const mode = camData.awb_mode || 'auto';
  document.getElementById('diagCamAwbMode').value = mode;
  if (camData.awb_gains) {
    const parts = camData.awb_gains.split(',');
    document.getElementById('diagCamAwbRed').value = parts[0] || '';
    document.getElementById('diagCamAwbBlue').value = parts[1] || '';
  }
  diagCamAwbModeChanged();
  if (camData.is_csi === false) {
    document.getElementById('diagCamAwbNote').innerHTML =
      '<strong>USB camera detected</strong> — white balance has no effect on this backend, only on a CSI/Pi Camera Module. Settings below will save but won\'t change anything until a CSI camera is used.';
  }
}

async function diagSaveCameraAwb() {
  const statusEl = document.getElementById('diagCamAwbStatus');
  const mode = document.getElementById('diagCamAwbMode').value;
  let gains = '';
  if (mode === 'custom') {
    const r = document.getElementById('diagCamAwbRed').value;
    const b = document.getElementById('diagCamAwbBlue').value;
    if (!r || !b) {
      statusEl.textContent = 'Error: both R and B gains are required for custom mode.';
      return;
    }
    gains = `${r},${b}`;
  }
  statusEl.textContent = 'Saving…';
  try {
    const fd = new FormData();
    fd.append('action', 'set_camera_wb');
    fd.append('awb_mode', mode);
    fd.append('awb_gains', gains);
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_tune.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const data = await res.json();
    if (data.status !== 'OK') {
      statusEl.textContent = 'Error: ' + (data.message || 'save failed');
      return;
    }
    statusEl.textContent = 'Saved — restarting daemon…';
    const fd2 = new FormData();
    fd2.append('action', 'restart');
    await fetch('plugin.php?plugin=fpp-tally&page=www/control.php&nopage=1', { method: 'POST', body: fd2, cache: 'no-store' });
    statusEl.textContent = 'Applied.';
    setTimeout(() => { statusEl.textContent = ''; }, 4000);
  } catch (e) {
    statusEl.textContent = 'Request failed.';
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

// --- LD2410 threshold tuning ---------------------------------------------
let diagMinEnergyInitDone = false;

function diagCurrentThreshold() {
  const el = document.getElementById('diagMinEnergy');
  const v = parseFloat(el.value);
  return isNaN(v) ? 20 : v;
}

// Redraws immediately from the last-received data when the threshold
// input changes, instead of waiting up to 1s for the next poll tick --
// the whole point of a live threshold line is instant feedback while
// dragging/typing.
let diagLastLd2410 = null;
function diagRedrawGateBars() {
  if (!diagLastLd2410) return;
  const threshold = diagCurrentThreshold();
  document.getElementById('diagLd2410Body').innerHTML = `
    <div class="col-md-6">
      <div class="diag-side-title">Side A${diagLastLd2410.zone ? ' — ' + diagLastLd2410.zone : ''}</div>
      ${diagGateBars('A', diagLastLd2410.A, threshold)}
    </div>
    <div class="col-md-6">
      <div class="diag-side-title">Side B${diagLastLd2410.zone ? ' — ' + diagLastLd2410.zone : ''}</div>
      ${diagGateBars('B', diagLastLd2410.B, threshold)}
    </div>`;
  diagRenderLane(diagLastLd2410, threshold);
}

// --- Lane view -----------------------------------------------------------
let diagLaneSplitInitDone = false;

function diagCurrentLaneSplit() {
  const v = parseInt(document.getElementById('diagLaneSplit').value, 10);
  return isNaN(v) ? 4 : v;
}

function diagRenderLane(ld2410Data, threshold) {
  const road = document.getElementById('diagLaneRoad');
  if (!ld2410Data || (!ld2410Data.A?.connected && !ld2410Data.B?.connected)) {
    road.style.opacity = '0.4';
    document.getElementById('diagLaneGates').innerHTML = '';
    document.getElementById('diagLaneCar').style.opacity = '0';
    document.getElementById('diagLaneNear').classList.remove('occupied');
    document.getElementById('diagLaneFar').classList.remove('occupied');
    document.getElementById('diagLaneNearStatus').textContent = '';
    document.getElementById('diagLaneFarStatus').textContent = '';
    return;
  }
  road.style.opacity = '1';

  // Combined per-gate energy: A and B are co-located, so for each gate
  // take whichever side currently reads stronger -- a real target in
  // front of the mailbox should register on both, but aim/wiring/noise
  // can make one side read weaker at any given instant.
  const gA = ld2410Data.A?.gate_move_energy || [];
  const gB = ld2410Data.B?.gate_move_energy || [];
  const sA = ld2410Data.A?.gate_static_energy || [];
  const sB = ld2410Data.B?.gate_static_energy || [];
  const combined = [];
  for (let g = 0; g < DIAG_NUM_GATES; g++) {
    const move = Math.max(gA[g] || 0, gB[g] || 0);
    const stat = Math.max(sA[g] || 0, sB[g] || 0);
    combined.push(Math.max(move, stat));
  }

  const split = diagCurrentLaneSplit();
  const nearOccupied = combined.slice(0, split).some(e => e >= threshold);
  const farOccupied = combined.slice(split).some(e => e >= threshold);

  // Road graphic: gate 0 sits at the mailbox (left edge), gate 8 at the
  // far edge -- the divider and the two lane widths are placed
  // proportionally along that same axis, so "near lane" / "far lane"
  // visually line up with the actual gate numbers underneath instead of
  // being an arbitrary 50/50 split.
  const dividerPct = (split / DIAG_NUM_GATES) * 100;
  document.getElementById('diagLaneDivider').style.left = dividerPct + '%';
  const nearEl = document.getElementById('diagLaneNear');
  const farEl = document.getElementById('diagLaneFar');
  nearEl.style.left = '0%';
  nearEl.style.width = dividerPct + '%';
  farEl.style.left = dividerPct + '%';
  farEl.style.width = (100 - dividerPct) + '%';
  nearEl.classList.toggle('occupied', nearOccupied);
  farEl.classList.toggle('occupied', farOccupied);
  document.getElementById('diagLaneNearStatus').textContent = nearOccupied ? 'Vehicle' : '';
  document.getElementById('diagLaneFarStatus').textContent = farOccupied ? 'Vehicle' : '';

  // Car marker: placed at whichever single gate currently reads
  // strongest (same gate the "•" marker on the readout above points at),
  // only shown while something is actually above threshold somewhere.
  const carEl = document.getElementById('diagLaneCar');
  let peakGate = -1, peakVal = -1;
  for (let g = 0; g < DIAG_NUM_GATES; g++) {
    if (combined[g] > peakVal) { peakVal = combined[g]; peakGate = g; }
  }
  if (peakVal >= threshold && peakGate >= 0) {
    const carPct = ((peakGate + 0.5) / DIAG_NUM_GATES) * 100;
    carEl.style.left = carPct + '%';
    carEl.style.opacity = '1';
  } else {
    carEl.style.opacity = '0';
  }

  // Small gate strip under the lanes -- same combined data, just a
  // compact reference so the lane split is visibly tied to real gate
  // numbers rather than an opaque near/far label.
  let gatesHtml = '';
  for (let g = 0; g < DIAG_NUM_GATES; g++) {
    const pct = diagGatePct(combined[g]);
    const over = combined[g] >= threshold;
    const color = over ? (g < split ? '#36a2eb' : '#ff9f40') : 'rgba(255,255,255,0.1)';
    gatesHtml += `<div class="diag-lane-gate" style="background:${over ? color : 'rgba(255,255,255,0.1)'};opacity:${over ? 1 : 0.4}" title="Gate ${g}: ${combined[g]}"></div>`;
  }
  document.getElementById('diagLaneGates').innerHTML = gatesHtml;

  // Last pass, from the daemon's own live-state (see ld2410.py) -- real
  // measured A<->B transit time / speed = sensor spacing / transit,
  // not just a pass/fail check against sequence_window_s.
  const lastPassEl = document.getElementById('diagLastPass');
  const lp = ld2410Data.last_pass;
  if (!lp) {
    lastPassEl.textContent = 'No pass recorded yet this session.';
  } else {
    const ageS = Math.max(0, Math.round(Date.now() / 1000 - lp.ts));
    const mph = lp.speed_mps != null ? (lp.speed_mps * 2.23694).toFixed(1) + ' mph' : 'speed unknown';
    const likely = lp.speed_mps != null
      ? (lp.speed_mps < 2.5 ? ' — likely stopping to view' : ' — quick pass-by')
      : '';
    lastPassEl.innerHTML = `Last pass: <strong>${lp.direction}</strong>, ${mph}${likely} <span class="text-muted">(${lp.transit_s}s transit, ${ageS}s ago)</span>`;
  }
}

async function diagSaveLaneSplit() {
  const statusEl = document.getElementById('diagLaneSplitStatus');
  const value = diagCurrentLaneSplit();
  statusEl.textContent = 'Saving…';
  try {
    const fd = new FormData();
    fd.append('action', 'set_lane_split');
    fd.append('lane_split_gate', value);
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_tune.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const data = await res.json();
    if (data.status !== 'OK') {
      statusEl.textContent = 'Error: ' + (data.message || 'save failed');
      return;
    }
    statusEl.textContent = 'Saved — restarting daemon…';
    const fd2 = new FormData();
    fd2.append('action', 'restart');
    await fetch('plugin.php?plugin=fpp-tally&page=www/control.php&nopage=1', { method: 'POST', body: fd2, cache: 'no-store' });
    statusEl.textContent = 'Applied.';
    setTimeout(() => { statusEl.textContent = ''; }, 4000);
  } catch (e) {
    statusEl.textContent = 'Request failed.';
  }
}

// --- Per-gate native sensitivity ------------------------------------------
function diagGateSensClear() {
  document.getElementById('diagGateSensBody').innerHTML =
    '<div class="text-muted small">Click "Read Current Values" to load this side\'s current per-gate sensitivity from the radar.</div>';
  document.getElementById('diagGateSensStatus').textContent = '';
}

function diagRenderGateSensTable(motionVals, staticVals) {
  let html = '<div style="overflow-x:auto;"><table class="diag-gatesens-table"><thead><tr>' +
    '<th>Gate</th><th>Range</th><th>Motion sensitivity</th><th>Static sensitivity</th><th></th>' +
    '</tr></thead><tbody>';
  for (let g = 0; g < DIAG_NUM_GATES; g++) {
    const rangeM = (g * 0.75).toFixed(2) + '–' + ((g + 1) * 0.75).toFixed(2) + 'm';
    html += `<tr>
      <td>G${g}</td>
      <td class="text-muted">${rangeM}</td>
      <td><input type="number" min="0" max="100" value="${motionVals[g] ?? ''}" id="diagGateMotion${g}"></td>
      <td><input type="number" min="0" max="100" value="${staticVals[g] ?? ''}" id="diagGateStatic${g}"></td>
      <td><button type="button" class="tally-btn tally-btn-sm" onclick="diagSetGateSensitivity(${g})"><i class="fas fa-floppy-disk"></i> Set</button></td>
    </tr>`;
  }
  html += '</tbody></table></div>';
  document.getElementById('diagGateSensBody').innerHTML = html;
}

async function diagReadGateSens() {
  const side = document.getElementById('diagGateSensSide').value;
  const statusEl = document.getElementById('diagGateSensStatus');
  statusEl.textContent = 'Reading from radar…';
  try {
    const fd = new FormData();
    fd.append('action', 'read');
    fd.append('side', side);
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_gates.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const data = await res.json();
    if (data.status !== 'OK') {
      statusEl.textContent = 'Error: ' + (data.message || 'read failed');
      return;
    }
    diagRenderGateSensTable(data.motion_sensitivity || [], data.static_sensitivity || []);
    statusEl.textContent = 'Loaded from side ' + side + '.';
  } catch (e) {
    statusEl.textContent = 'Request failed.';
  }
}

async function diagSetGateSensitivity(gate) {
  const side = document.getElementById('diagGateSensSide').value;
  const statusEl = document.getElementById('diagGateSensStatus');
  const motion = document.getElementById('diagGateMotion' + gate).value;
  const static_ = document.getElementById('diagGateStatic' + gate).value;
  statusEl.textContent = `Writing gate ${gate}…`;
  try {
    const fd = new FormData();
    fd.append('action', 'write');
    fd.append('side', side);
    fd.append('gate', gate);
    fd.append('motion', motion);
    fd.append('static', static_);
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_gates.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const data = await res.json();
    statusEl.textContent = data.status === 'OK' ? `Gate ${gate} saved.` : 'Error: ' + (data.message || 'write failed');
  } catch (e) {
    statusEl.textContent = 'Request failed.';
  }
}

async function diagSaveMinEnergy() {
  const statusEl = document.getElementById('diagMinEnergyStatus');
  const value = diagCurrentThreshold();
  statusEl.textContent = 'Saving…';
  try {
    const fd = new FormData();
    fd.append('action', 'set_min_energy');
    fd.append('min_energy', value);
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_tune.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const data = await res.json();
    if (data.status !== 'OK') {
      statusEl.textContent = 'Error: ' + (data.message || 'save failed');
      return;
    }
    statusEl.textContent = 'Saved — restarting daemon…';
    const fd2 = new FormData();
    fd2.append('action', 'restart');
    await fetch('plugin.php?plugin=fpp-tally&page=www/control.php&nopage=1', { method: 'POST', body: fd2, cache: 'no-store' });
    statusEl.textContent = 'Applied.';
    setTimeout(() => { statusEl.textContent = ''; }, 4000);
  } catch (e) {
    statusEl.textContent = 'Request failed.';
  }
}

// --- Camera-assisted tuning -----------------------------------------------
let diagCamTuneSuggested = null;

function diagCamTuneRenderBucket(elId, bucket) {
  const el = document.getElementById(elId);
  if (!bucket || !bucket.count) { el.textContent = '0 samples'; return; }
  el.textContent = `${bucket.count} samples — energy ${bucket.min_energy}–${bucket.max_energy} (avg ${bucket.avg_energy})`;
}

async function diagCamTuneRefresh() {
  let data;
  try {
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_camera_stats.php&nopage=1', { cache: 'no-store' });
    data = await res.json();
  } catch (e) {
    return;
  }

  diagCamTuneRenderBucket('diagCamTuneVehicleStats', data.vehicle);
  diagCamTuneRenderBucket('diagCamTuneNonVehicleStats', data.not_vehicle);

  const suggestionEl = document.getElementById('diagCamTuneSuggestion');
  const applyBtn = document.getElementById('diagCamTuneApplyBtn');
  diagCamTuneSuggested = data.suggested_min_energy;
  if (diagCamTuneSuggested != null) {
    const current = diagCurrentThreshold();
    suggestionEl.textContent = `Suggested min_energy: ${diagCamTuneSuggested} (current: ${current})`;
    applyBtn.disabled = false;
  } else {
    const need = data.min_samples_required ?? 15;
    suggestionEl.textContent = `Collecting data — need at least ${need} vehicle and ${need} non-vehicle samples.`;
    applyBtn.disabled = true;
  }
}

async function diagCamTuneApply() {
  if (diagCamTuneSuggested == null) return;
  const statusEl = document.getElementById('diagCamTuneApplyStatus');
  document.getElementById('diagMinEnergy').value = diagCamTuneSuggested;
  diagRedrawGateBars();
  statusEl.textContent = 'Applying…';
  try {
    const fd = new FormData();
    fd.append('action', 'set_min_energy');
    fd.append('min_energy', diagCamTuneSuggested);
    const res = await fetch('plugin.php?plugin=fpp-tally&page=www/diag_tune.php&nopage=1', { method: 'POST', body: fd, cache: 'no-store' });
    const data = await res.json();
    if (data.status !== 'OK') {
      statusEl.textContent = 'Error: ' + (data.message || 'save failed');
      return;
    }
    statusEl.textContent = 'Saved — restarting daemon…';
    const fd2 = new FormData();
    fd2.append('action', 'restart');
    await fetch('plugin.php?plugin=fpp-tally&page=www/control.php&nopage=1', { method: 'POST', body: fd2, cache: 'no-store' });
    statusEl.textContent = 'Applied.';
    setTimeout(() => { statusEl.textContent = ''; }, 4000);
  } catch (e) {
    statusEl.textContent = 'Request failed.';
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

  if (!diagMinEnergyInitDone && data.ld2410_min_energy != null) {
    diagMinEnergyInitDone = true;
    document.getElementById('diagMinEnergy').value = data.ld2410_min_energy;
  }

  if (!diagLaneSplitInitDone && data.ld2410 && data.ld2410.lane_split_gate != null) {
    diagLaneSplitInitDone = true;
    document.getElementById('diagLaneSplit').value = data.ld2410.lane_split_gate;
  }

  diagInitCameraAwb(data.camera);

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
    diagRenderLane(null, diagCurrentThreshold());
  } else if (!data.ld2410) {
    ld2410Body.innerHTML = '<div class="col-12 text-muted small">No radar data yet — waiting for the daemon.</div>';
    diagRenderLane(null, diagCurrentThreshold());
  } else {
    diagRenderLane(data.ld2410, diagCurrentThreshold());
    diagLastLd2410 = data.ld2410;
    const threshold = diagCurrentThreshold();
    ld2410Body.innerHTML = `
      <div class="col-md-6">
        <div class="diag-side-title">Side A${data.ld2410.zone ? ' — ' + data.ld2410.zone : ''}</div>
        ${diagGateBars('A', data.ld2410.A, threshold)}
      </div>
      <div class="col-md-6">
        <div class="diag-side-title">Side B${data.ld2410.zone ? ' — ' + data.ld2410.zone : ''}</div>
        ${diagGateBars('B', data.ld2410.B, threshold)}
      </div>`;
  }

  const camTuneEnabled = !!data.modules.camera;
  document.getElementById('diagCamTuneDisabled').style.display = camTuneEnabled ? 'none' : '';
  document.getElementById('diagCamTuneBody').style.display = camTuneEnabled ? '' : 'none';
  const lastClassify = data.camera && data.camera.classify;
  if (camTuneEnabled && lastClassify && lastClassify.label) {
    const ageS = lastClassify.stale ? ' (stale)' : '';
    const conf = lastClassify.confidence != null ? ` (${Math.round(lastClassify.confidence * 100)}%)` : '';
    document.getElementById('diagCamTuneLast').textContent = `Last classified: ${lastClassify.label}${conf}${ageS}`;
  } else if (camTuneEnabled) {
    document.getElementById('diagCamTuneLast').textContent = 'No classification yet this session.';
  }

  diagRenderThermal(document.getElementById('diagThermalBody'), data.thermal, data.modules.thermal);
  diagRenderEntranceLane(data.thermal, data.modules.thermal);

  document.getElementById('diagThermalTuneDisabled').style.display = data.modules.thermal ? 'none' : '';
  document.getElementById('diagThermalTuneBody').style.display = data.modules.thermal ? '' : 'none';
  if (data.modules.thermal) diagInitThermalTune(data.thermal_config);

  diagBleTable(document.getElementById('diagBleBody'), data.crowd_ble, data.modules.crowd_ble);
  diagWifiTable(document.getElementById('diagWifiBody'), data.crowd_wifi, data.modules.crowd_wifi,
    `Interface: ${data.wifi_interface}. Requires monitor mode + elevated privileges — see the Setup page's Crowd Scan Config warning if this stays empty.`);
}

diagInitCollapsibles();
diagPoll();
const diagInterval = setInterval(diagPoll, 1000);
// Sample-count/suggestion stats change slowly (one classification every
// few seconds at most, per camera.py's min_interval_s throttle) -- a
// separate, much slower interval avoids hammering a SQLite query every
// single 1s diagPoll tick for no benefit.
diagCamTuneRefresh();
const diagCamTuneInterval = setInterval(diagCamTuneRefresh, 5000);
window.addEventListener('beforeunload', () => {
  clearInterval(diagInterval);
  clearInterval(diagCamTuneInterval);
  diagCamRunning = false;
  if (diagCamTimer) clearTimeout(diagCamTimer);
});
</script>
