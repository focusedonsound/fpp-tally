"""
crowd_ble.py — Tally's BLE crowd/device-estimate module (Option 3a).

Passive scan for nearby BLE advertisements via onboard Bluetooth, on a
configurable interval (default 60s), counting unique addresses seen in
each scan window and handing the raw count to the daemon, which applies
the user-configured offset and floors at zero (see tally_daemon.py's
_handle_scan_event()).

⚠️ Modern devices randomize their BLE MAC address by default (iOS and
Android both do this out of the box), so this is a relative crowd-density
indicator, not an exact headcount — the same caveat Baldrick Signals
documents for the same reason. Say this prominently in the UI, not just
here (see www/index.php's Crowd Scan Config card).

Per-device detail (address type, name, RSSI, manufacturer ID, first/last
seen within the scan window) is captured for the Diagnostics page only —
never persisted to the DB, same "live-only" reasoning as the rest of
_write_live_state()'s callers. This exists to give a builder enough
signal to eventually filter the raw scan down to "likely a visitor's
phone" vs. a fixed/paired BLE peripheral that always shows up (a smart
bulb, a TV remote, a doorbell) — that filtering logic doesn't exist yet,
this is the groundwork for deciding what it should look like. address_type
comes from bleak's BlueZ backend raw device properties (device.details
-> props -> AddressType) — confirmed against the actual installed bleak
3.0.2 source on real hardware, not documented as a stable cross-backend
API by bleak itself, so it degrades to None rather than raising on any
other platform/version.

Not validated against a live crowd of real devices (no field-test
opportunity during development) — the scan/count/offset pipeline itself is
exercised by unit tests using a fake scanner (see the test harness used
during development), same as the other modules.
"""
from __future__ import annotations

import asyncio
import time

from .base import SensorModule

try:
    from bleak import BleakScanner
    BLEAK_AVAILABLE = True
except ImportError:
    BLEAK_AVAILABLE = False
    BleakScanner = None  # type: ignore

# A handful of Bluetooth SIG company IDs worth naming directly in the
# Diagnostics table -- these are the vendors overwhelmingly likely to mean
# "this is somebody's phone" (Apple's Continuity/nearby-interaction and
# Google's Fast Pair/cross-device packets are broadcast continuously by
# stock iOS/Android, even with the device name hidden). Deliberately not
# the full official assigned-numbers list (thousands of entries or
# providers of cheap generic BLE chips a builder would want to filter
# OUT, not recognize) -- just enough to eyeball "yes, likely a phone" at
# a glance while tuning.
_KNOWN_VENDORS = {
    0x004C: "Apple",
    0x0006: "Microsoft",
    0x00E0: "Google",
    0x0075: "Samsung",
}


def _vendor_names(manufacturer_ids: list) -> list:
    return sorted({_KNOWN_VENDORS[i] for i in manufacturer_ids if i in _KNOWN_VENDORS})


async def _scan_once_detailed(timeout_s: float, scanner_factory=None) -> dict:
    """One BLE discovery pass using a detection callback (not
    BleakScanner.discover()) so each advertisement's own arrival time is
    captured, not just a single end-of-scan snapshot -- first_seen/
    last_seen reflect real timestamps within this scan window, not an
    estimate. Returns {address: {...}}, keyed by address so repeat
    advertisements from the same device update one entry instead of
    duplicating it. scanner_factory is injectable for testing without a
    real adapter."""
    devices: dict = {}

    def _on_advertisement(device, adv_data) -> None:
        now = time.time()
        address_type = None
        try:
            # BlueZ-specific: device.details = {"path": ..., "props": {...}}
            # where props is the raw org.bluez.Device1 property set,
            # which includes AddressType ("public" or "random"). Any
            # other backend/shape just leaves this None -- diagnostic
            # enrichment must never be able to crash the scan itself.
            address_type = (device.details or {}).get("props", {}).get("AddressType")
        except Exception:
            pass

        manufacturer_ids = sorted(adv_data.manufacturer_data.keys()) if adv_data.manufacturer_data else []
        entry = devices.setdefault(device.address, {
            "address": device.address,
            "first_seen": now,
        })
        entry["address_type"] = address_type
        entry["name"] = adv_data.local_name or device.name
        entry["rssi"] = adv_data.rssi
        entry["manufacturer_ids"] = [f"0x{i:04x}" for i in manufacturer_ids]
        entry["vendors"] = _vendor_names(manufacturer_ids)
        entry["last_seen"] = now

    make_scanner = scanner_factory or (lambda cb: BleakScanner(detection_callback=cb))
    scanner = make_scanner(_on_advertisement)
    await scanner.start()
    try:
        await asyncio.sleep(timeout_s)
    finally:
        await scanner.stop()
    return devices


class CrowdBLEModule(SensorModule):
    name = "crowd_ble"

    def run(self) -> None:
        if not BLEAK_AVAILABLE:
            self.log.warning(
                "bleak not importable — BLE crowd-scan module idle. "
                "Install the optional pip dependency (see fpp_install.sh)."
            )
            while not self._stop.is_set():
                time.sleep(5)
            return

        cs_cfg = self.cfg.get("crowd_scan", {}) or {}
        interval_s = max(10, int(cs_cfg.get("interval_s", 60)))
        # Scan window itself is shorter than the interval so there's idle
        # time between scans -- BLE scanning is not free on the Pi's own
        # radio (it briefly competes with anything else using Bluetooth),
        # and a 60s-long continuous scan would defeat "periodic sampling."
        scan_timeout_s = min(10.0, interval_s / 2.0)

        self.log.info("BLE crowd-scan module running: interval=%ds scan_window=%.1fs",
                       interval_s, scan_timeout_s)

        while not self._stop.is_set():
            try:
                devices = asyncio.run(_scan_once_detailed(scan_timeout_s))
                addresses = sorted(devices.keys())
                self._emit(kind="scan", source="ble", raw_count=len(addresses))
                # Diagnostics-only, never persisted to the DB history --
                # see _write_live_state()'s docstring for why.
                # interval_s lets the Diagnostics page judge staleness
                # against this module's own scan cadence rather than a
                # fixed threshold -- a 60s-interval module's data is still
                # "live" 40s after the last write, unlike ld2410's 0.5s
                # heartbeat.
                self._write_live_state("crowd_ble_live.json", {
                    "devices": [devices[a] for a in addresses],
                    "count": len(addresses),
                    "interval_s": interval_s,
                })
                self.log.debug("[BLE] scan: %d unique addresses", len(addresses))
            except Exception as exc:
                # Covers "no Bluetooth adapter", permission errors, and any
                # transient bleak/dbus hiccup -- one bad scan must not stop
                # the next one from being attempted.
                self.log.warning("BLE scan failed: %s", exc)

            # Sleep the remainder of the interval in short slices so
            # pluginStop's join(timeout=5) doesn't have to wait out a full
            # 60s+ interval before the thread actually exits.
            slept = 0.0
            while slept < (interval_s - scan_timeout_s) and not self._stop.is_set():
                time.sleep(min(1.0, interval_s - scan_timeout_s - slept))
                slept += 1.0
