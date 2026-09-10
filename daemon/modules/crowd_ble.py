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


async def _scan_once(timeout_s: float) -> list:
    """One BLE discovery pass. Returns the sorted list of unique addresses
    seen. Split out as its own coroutine so it's independently testable
    without running the module's full thread loop."""
    devices = await BleakScanner.discover(timeout=timeout_s)
    return sorted({d.address for d in devices})


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
                addresses = asyncio.run(_scan_once(scan_timeout_s))
                self._emit(kind="scan", source="ble", raw_count=len(addresses))
                # Diagnostics-only, never persisted to the DB history --
                # see _write_live_state()'s docstring for why.
                self._write_live_state("crowd_ble_live.json", {
                    "addresses": addresses,
                    "count": len(addresses),
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
