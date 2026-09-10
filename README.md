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

`tally.service` runs the daemon as the unprivileged `fpp` user (matching
the rest of Tally, and FPP's own plugin conventions). Raw 802.11 frame
capture needs elevated privileges, which this build does not request on
its own — that's a deliberate scope boundary pending a decision on how
much privilege escalation is appropriate for this plugin to request by
default, not an oversight. If you want to enable it yourself:

```bash
sudo setcap cap_net_raw,cap_net_admin=eip /usr/bin/python3.XX
```

(replace `python3.XX` with your system's actual interpreter binary, and
be aware this grants that capability to *every* script run by that
interpreter, not just Tally's daemon — evaluate the tradeoff for your
system before doing this). You'll also need to put the configured
interface into monitor mode yourself (`sudo iw dev wlan1 set type
monitor`) before starting the daemon; Tally doesn't do this for you.

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
BME280) is now implemented. What's left before a 1.0: hardware validation
of thermal/BME280/WiFi against real sensors, the registration/licensing
backend (`fpp-tally-license-server`), the hidden calibration route's
actual camera-frame capture, and the Reporting page's 7/30/90/365-day
chart views.

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
