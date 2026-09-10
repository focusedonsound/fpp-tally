# Tally — FPP Vehicle & Crowd Counter Plugin

Tally counts vehicles passing your holiday light show, classifies them as
pass-through vs. parked-to-watch, tracks direction, optionally estimates
nearby-device/crowd counts, logs everything for reporting, and can trigger
FPP playlists/effects live in response to traffic.

**Modular hardware tiers** — not every install needs the same sensors:

| Option | Hardware | Status |
|---|---|---|
| 1 | 2× HLK-LD2410B radar (driveway/zone) | ✅ Working |
| 2 | MLX90640 thermal array (entrance/street zone) | ⚠️ Implemented, not yet hardware-validated |
| 3a | Onboard Bluetooth (BLE crowd/device estimate) | ✅ Working |
| 3b | Second USB WiFi adapter (monitor-mode crowd estimate) | ⚠️ Implemented, needs elevated daemon privileges — see below |
| 4 | BME280 temperature/humidity | ⚠️ Implemented, not yet hardware-validated |
| 4b | DHT11 temperature/humidity *(not in the original spec)* | ✅ Working |

Options 1 and 2 are not mutually exclusive — run either alone, or both for a
full entrance+driveway picture. Every module is independently enabled from
the Setup page's hardware-selection wizard; the daemon only loads/polls
modules you've actually turned on, and one module's hardware being absent
never blocks the others.

## What's real in this release (v0.1.0)

- Daemon core, SQLite schema (`events`, `device_scans`, `environment`),
  conditional module loading, systemd service with auto-restart
- LD2410B module fully working — dual-radar sequence-window direction
  detection and parked-timeout dwell logic, ported from the proven
  implementation in [fpp-sled-mailbox](https://github.com/focusedonsound/fpp-sled-mailbox)
- MQTT + Home Assistant auto-discovery (per-zone car/direction/parked
  sensors, daemon status via LWT)
- FPP Command registration: "Trigger Test Vehicle Event" (per zone) and
  "Trigger Test Crowd Scan" — verify your DB/MQTT/FPP-trigger wiring with
  zero sensor hardware connected
- Setup page: hardware-selection wizard, zone/direction labels, LD2410B
  config, FPP trigger assignment, MQTT config, daemon start/stop/restart,
  registration (soft gate on the web UI only — never on detection/logging)
- Reporting page: live per-zone counters, recent-events table
- Hidden, password-gated, auto-expiring developer calibration route
  (activation/expiry/audit-log gating implemented; the camera-frame preview
  itself is not — no camera integration yet)

## What's new in v0.2.0

- **Thermal (MLX90640) module implemented** — frame differencing / blob
  detection on the 32×24 grid, centroid tracking, edge-crossing direction
  classification, parked-dwell detection (whose eventual departure still
  logs a normal directional pass, per spec), and a best-effort geometric
  speed estimate from your configured mount height/angle/distance.
  Unit-tested against synthetic frames (blob detection, background
  subtraction, direction, parked-then-departure, speed estimate, and
  graceful idling when the sensor library isn't installed) — **but not yet
  validated against a real MLX90640**, since no thermal sensor has been
  available during development. Treat the blob-threshold and
  min-travel-columns defaults as a starting point to tune once you have
  the hardware wired up.

## What's new in v0.3.0

- **BLE crowd-scan module (Option 3a) implemented** — passive scan via
  onboard Bluetooth on a configurable interval, counting unique addresses
  per scan window. Works out of the box on the daemon's default
  unprivileged user.
- **WiFi crowd-scan module (Option 3b) implemented** — passive 802.11
  probe-request sniffing on a monitor-mode interface, same
  count-unique-per-window approach. **Requires elevated privileges** Tally
  does not grant itself (see "Enabling WiFi crowd scanning" below) — logs
  a clear, one-time diagnostic and idles rather than retrying forever if
  it can't open a raw socket.
- When both BLE and WiFi are enabled, the daemon publishes the higher of
  their two latest readings as a combined estimate, rather than summing
  them — their identifiers are unrelated address spaces (a phone's BLE and
  WiFi MACs are randomized independently), so summing would double-count
  every device visible on both radios.
- Both modules' scan/dedup logic is unit-tested against fake
  scanner/sniffer implementations (no real BLE crowd or WiFi hardware
  available to field-test against during development).

### Enabling WiFi crowd scanning

The Setup page's Crowd Scan Config card defaults the WiFi interface to
`wlan0` — the Pi's onboard adapter. That's only safe if this Pi reaches
*its own* network some other way (Ethernet, or no network at all);
putting wlan0 into monitor mode while it's your active WiFi connection
will drop that connection. If this Pi is on WiFi for its own network,
plug in a second USB WiFi adapter for scanning and set its interface
name (commonly `wlan1`) on the Setup page instead. Not auto-detected —
network state can change after any check Tally could do at startup, so
this is a decision left to the builder.

`tally.service` runs the daemon as the unprivileged `fpp` user (matching
the rest of Tally, and FPP's own plugin conventions). Raw 802.11 frame
capture needs elevated privileges, which this build does not request on
its own — that's a deliberate scope boundary pending a decision on how
much privilege escalation is appropriate for this plugin to request by
default, not an oversight. If you want to enable it yourself:

```bash
sudo setcap cap_net_raw,cap_net_admin=eip /usr/bin/python3.XX
sudo setcap cap_net_admin=eip $(readlink -f $(which iw))
```

(replace `python3.XX` with your system's actual interpreter binary --
`readlink -f $(which python3)` finds it -- and be aware the first command
grants that capability to *every* script run by that interpreter, not
just Tally's daemon; evaluate the tradeoff for your system before doing
this). **Both commands are needed**, not just the first one: the daemon
opens its own raw socket in-process (covered by the python3 grant), but
it also shells out to `iw` to hop across channels 1/6/11 during each scan
(confirmed necessary on real hardware — a monitor-mode adapter sits on
whatever channel `iw` last set it to, so without hopping the module only
ever sees devices probing on that one fixed channel, which read as
"0 devices nearby" even with real phones in range). `iw` running as its
own subprocess doesn't inherit the daemon's own capability grant, so it
needs the second `setcap` line to be able to change channels itself. If
only the first is set, WiFi scanning will still run without the earlier
permission error, but stays parked on one channel — check the daemon log
for "Channel hopping unavailable" if scan results seem too low.

You'll also need to put the configured interface into monitor mode
yourself (`sudo iw dev wlan0 set type monitor`, or `wlan1` etc. if you're
using a second adapter) before starting the daemon; Tally doesn't do this
for you. Confirmed on real hardware: the Raspberry Pi's own onboard WiFi
chip (`brcmfmac` driver, e.g. the 3B+'s built-in adapter) does **not**
support monitor mode at all (`iw` fails with "Operation not supported")
— if that's your situation, you need a genuine external USB adapter with
a monitor-mode-capable chipset (confirmed working: RTL8192CU) rather than
defaulting to the onboard adapter.

## What's new in v0.4.0

- **BME280 module (Option 4) implemented** — polls temperature/humidity
  over I2C on a configurable interval (default 10 min), logs to the
  `environment` table. Shares the I2C bus with the MLX90640 at a different
  address, no conflict, no multiplexer needed. Purely contextual data for
  the Reporting page — no detection logic.
- Not yet validated against real BME280 hardware — none has been available
  during development. The °C→°F conversion and the poll loop's
  per-reading error recovery are unit-tested against a fake sensor object.

Every module described in the project spec (LD2410B, thermal, BLE, WiFi,
BME280) is now implemented.

## What's new in v0.5.0

- **Registration wired to a real license server** —
  [fpp-tally-license-server](https://github.com/focusedonsound/fpp-tally-license-server)
  (Cloudflare Worker + D1) replaces the local-only registration stub.
- **Calibration route: camera-frame capture implemented** — ffmpeg + V4L2,
  works with a generic USB webcam.
- **Reporting page: full chart views** — per-zone stacked traffic
  (direction A/B + parked) with 7/30/90/365-day range selection, hourly
  distribution, and crowd/environment charts.
- Fixed a real bug found via testing: the hourly-distribution chart was
  silently bucketing by UTC instead of local time.

## DHT11 module added (not in the original spec)

The project spec calls for a BME280 (I2C), but DHT11 (single-wire digital,
same sensor `fpp-sled-mailbox` already supports) is what's actually
available to test against on real hardware — see `daemon/modules/dht11.py`.
Same `environment` event kind and DB table as BME280; enable whichever one
matches your actual sensor. Not yet validated against a real DHT11 — the
°C→°F conversion and the poll loop's checksum-failure recovery (DHT11
reads regularly fail transiently; that's normal for the protocol, not a
fault condition) are unit-tested against a fake sensor object.

## What's new in v0.6.0 — first real-hardware validation pass

Tested live on a real Raspberry Pi 3B+ (192.168.0.51) running alongside a
production `fpp-sled-mailbox` install using the same two LD2410B radars —
not a synthetic/container test. This is where "implemented" became
"working" for several modules, and surfaced two real bugs neither
container testing nor unit tests had caught:

- **LD2410B (Option 1): confirmed working against real radar hardware.**
  Found and fixed a real bug in the process: a car sitting parked longer
  than `parked_timeout_s` produced a fresh "parked" event on almost every
  ~1s poll cycle for as long as it stayed put, instead of exactly once —
  44 duplicate rows in under 90 seconds during testing. Root cause:
  `on_presence()` was unconditionally clearing the "already fired" latch
  on every poll where the radar reported presence, not just on the rising
  edge. Fixed, and locked in with a regression test (400 simulated seconds
  of continuous presence now correctly produces exactly one event).
- **BLE crowd-scan (Option 3a): confirmed working.** First real scan on
  the Pi's onboard Bluetooth found 12 unique nearby devices.
- **DHT11 (Option 4b, not in the original spec): confirmed working.**
  Real reading: 74.5°F / 55% humidity on GPIO4.
- **Calibration camera capture: confirmed working end-to-end** with a USB
  webcam — real 1920×1080 JPEG captured through the full activate →
  session-gated snapshot → HTTP pipeline.
- **Two real installer bugs found and fixed**, both invisible to
  container-based testing (which had no unprivileged web-server user to
  expose them): `tally.json` and the plugin's own log file were left
  `root:root` after install, so the Setup page's Save button and the
  daemon's own file-based logging would silently fail on a real FPP
  install. Confirmed by hitting the actual `PermissionError` through the
  real Setup page, not just inspecting file modes. Also worth noting:
  `hdmi_cec.json` and `sled.json` on the same real Pi were already
  correctly owned despite neither of those plugins' install scripts doing
  this chown either — almost certainly hand-fixed via SSH at some point
  rather than fixed at the source, suggesting the same gap likely exists
  in those install scripts too.
- Confirmed the SLED/Tally serial-port coexistence plan works safely in
  practice: stopped SLED, ran Tally's LD2410B module against the freed
  ports, stopped Tally, restarted SLED — verified fully healthy afterward
  (both radars reopened cleanly, MQTT reconnected, no errors).

What's left before a 1.0: hardware validation of thermal/BME280/WiFi
crowd-scan against real sensors (no MLX90640, no second monitor-mode WiFi
adapter available during this pass), and the registration/licensing
backend's premium-tier logic once that's actually defined.

## What's new in v0.7.0 — Diagnostics page

- **New Diagnostics page** (linked from Setup, not part of the main nav) —
  a live-only view, polled every second, never written to the database:
  - **LD2410B per-gate readout** for sides A/B: moving and static energy
    per gate (0–8), matching the same engineering-mode detail
    fpp-sled-mailbox's own diagnostic mode shows. Radars now run in
    engineering mode from the moment they connect; falls back to basic
    mode automatically (present/distance/energy only, no per-gate detail)
    if a unit doesn't accept the mode-switch — direction/parked detection
    is unaffected either way.
  - **Raw BLE scan**: the sorted list of unique addresses from the most
    recent scan window, not just the count.
  - **Raw WiFi scan**: same, for probe-request source addresses.
  - **MLX90640 thermal delta grid**: the 32×24 background-subtracted heat
    grid rendered as a canvas heatmap, with the currently-tracked blob(s)
    circled. Real detection logic, same as the daemon's own
    frame-differencing/blob-tracking pipeline — not yet hardware-validated
    (no MLX90640 available during development), so treat the color
    scaling as a starting point to eyeball-tune once you have the sensor.
  - **Camera Reference panel**: a live snapshot (refreshed every 1.5s),
    positioned next to the radar readout specifically so you can watch a
    car cross the zone and see which gate lights up / which thermal blob
    tracks it at the same real-world position — useful for tuning
    `min_energy`, gate-based thresholds, and mount angle/height without
    guessing. Reuses the same camera-capture approach as the hidden
    calibration route (`ffmpeg` + V4L2), but as its own endpoint
    (`diag_snapshot.php`) so that route stays untouched and unreferenced
    by this now-discoverable page.
  - Each panel shows a "stale" badge once its source module hasn't
    written fresh data in the expected window (e.g. module disabled, or
    daemon stopped) rather than showing frozen data as if it were live —
    BLE/WiFi judge staleness against their own configured scan interval,
    not a fixed timer, after real testing showed a 30s-interval scan
    sitting "Stale" for 25+ of every 30 seconds under a naive fixed
    threshold.
- **Camera panel access control**: off by default
  (`calibration.require_password_on_diagnostics: false` in tally.json) —
  during active development on a build only you can reach, the camera
  panel just works, same as the rest of the Diagnostics page. **Flip
  this to `true` before handing the build to anyone else** (or leaving it
  reachable on a network you don't fully trust); doing so gates the
  panel behind the same bcrypt password + session the hidden calibration
  route already uses (`calibration.password_hash` — see "Camera
  calibration mode" below for how to set it). The two routes intentionally
  share that one session: unlocking the camera on either page unlocks it
  on both, since it's the same grant.
- **WiFi crowd-scan now defaults to the onboard adapter (`wlan0`)**
  instead of assuming a second USB adapter is always required. This is
  only safe when the Pi reaches its own network some other way (e.g.
  Ethernet) — see the Setup page's Crowd Scan Config card and the README
  section above for when you need a second adapter instead. Not
  auto-detected: network state can change after any check Tally could do.
- **Two real bugs found and fixed testing this on 192.168.0.51**, on top
  of the ones already logged in v0.6.0:
  - Engineering-mode LD2410 frames had no plausibility bound on the
    distance fields (the basic-mode decoder already had one) — occasional
    USB-serial noise garbled a distance to a bogus ~32,000+ cm value,
    which the Diagnostics page would briefly display as a nonsense
    reading. Fixed by rejecting implausible frames outright, same bound
    the basic decoder already used.
  - The Reporting page's temperature/humidity card and history chart only
    checked `modules['bme280']` to decide whether to show themselves, so
    an install running DHT11 without BME280 — like 192.168.0.51 — had
    real temperature data being logged and MQTT-published the whole time
    with genuinely no way to see it on the Reporting page. Fixed to check
    `bme280 OR dht11`, matching the check the MQTT/HA-discovery code
    already had right.
  - The Diagnostics/calibration camera panels polled on a fixed setInterval
    regardless of how long the previous capture took; each capture opens
    the V4L2 device fresh via `ffmpeg`, which took ~3.2s on this Pi 3B+ —
    longer than the 1.5s poll gap — so overlapping requests fought over
    the same device and the feed visibly "started and stopped." Fixed by
    self-chaining each poll to only fire after the previous one settles,
    plus a non-blocking flock server-side as defense in depth.
  - **WiFi crowd-scan reported 0 devices with real phones in range**: a
    monitor-mode adapter stays parked on whatever channel `iw` last set
    it to, and the module never changed channels, so it only ever saw
    probe requests on that one fixed channel (confirmed: BLE saw 14 real
    devices in the same window WiFi saw 0). Fixed by hopping across
    channels 1/6/11 during each scan window — see the "Enabling WiFi
    crowd scanning" section above for the second `setcap` this needs.
  - Confirmed the Raspberry Pi's own onboard WiFi chip does not support
    monitor mode at all (driver-level limitation, not a Tally issue) —
    WiFi crowd-scan needs a genuine external USB adapter with a
    monitor-mode-capable chipset; confirmed working: RTL8192CU.

## Installation

Via FPP Plugin Manager (once listed), or manually:

```bash
cd /home/fpp/media/plugins
git clone https://github.com/focusedonsound/fpp-tally.git
sudo bash fpp-tally/fpp_install.sh
```

Then open **Content Setup → Tally**, check the hardware you have connected,
fill in serial ports / zone labels, and Save.

## Configuration

All settings live in `/home/fpp/media/config/tally.json` (see
`config/tally.json.example` for the full default shape and every field).

## Registration

Registration (email, free, one field) gates the **web UI only** — Setup and
Reporting pages. It never blocks the daemon: detection, logging, MQTT
publishing, and FPP triggers all run regardless of registration status.
Free vs. premium feature split is intentionally undefined for now — the
infrastructure exists so that decision can be made later without a
re-architecture. See [`fpp-tally-license-server`](https://github.com/focusedonsound/fpp-tally-license-server)
(planned — not yet built) for the real registration backend; today the
Setup page's registration flag is a local stub (any non-empty email marks
the install as registered).

## Setting the calibration password (developer only)

The hidden calibration route (`www/dev-calib-x9f3.php`) never sets its own
password — that's deliberate, so a stumbled-upon URL with no password
configured can do nothing. To set one, generate a bcrypt hash and hand-edit
`tally.json`:

```bash
php -r 'echo password_hash("your-password-here", PASSWORD_BCRYPT), PHP_EOL;'
```

Paste the result into `tally.json`'s `calibration.password_hash`. This mode
is not a community build option — see `BUILD_GUIDE.md` section on the
hidden camera and the project spec for why.

The same password also gates the **Diagnostics page's** camera panel, but
only once you turn that on — set `tally.json`'s
`calibration.require_password_on_diagnostics` to `true` (it defaults to
`false`, since the Diagnostics page is Setup-linked and visible, not
hidden like the calibration route). Like the password hash itself, this
is a hand-edit-the-config setting, not exposed anywhere in the web UI —
flip it before deploying anywhere you don't fully control access to.

## Requirements

- Falcon Player (FPP) 9.x or 10.x+
- Raspberry Pi 3B+ or compatible
- Python 3 with `pyserial`, `paho-mqtt` (installed automatically)

## License

Free for personal, hobbyist, and noncommercial use under the
[PolyForm Noncommercial License 1.0.0](LICENSE). Using this in a commercial
or paid-event display? A separate commercial license is required — contact
license.request@christmasinboontontwp.com to arrange one.

## Credits

**Author:** Nick Scilingo ([FocusedOnSound](https://github.com/focusedonsound))

Architecturally inspired by two of the author's other FPP plugins:
[fpp-sled-mailbox](https://github.com/focusedonsound/fpp-sled-mailbox)
(SQLite logging, MQTT/HA discovery, dashboard, systemd-daemon patterns, and
the LD2410B radar logic — ported directly) and
[fpp-EncoreRadio](https://github.com/focusedonsound/fpp-EncoreRadio)
(email registration / licensing pattern, to be reused for the planned
license server).

Bug reports: [github.com/focusedonsound/fpp-tally/issues](https://github.com/focusedonsound/fpp-tally/issues)
