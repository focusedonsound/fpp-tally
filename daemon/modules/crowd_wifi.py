"""
crowd_wifi.py — Tally's WiFi crowd/device-estimate module (Option 3b). STUB.

Not yet implemented — see the Tally project spec section 8. Intended design:
passive 802.11 probe-request sniffing via a second USB adapter in monitor
mode (no association, no content capture), same sampling approach as
crowd_ble.py, combined/deduped with it into a "combined" device_scans row
when both are enabled.

Known risk carried over from the project spec (section 13): confirm the
configured adapter's actual monitor-mode support before relying on this —
not every "supports monitor mode on Linux" USB WiFi adapter has a driver
that's plug-and-play, some need a manually compiled driver.
"""
from __future__ import annotations

import time

from .base import SensorModule


class CrowdWiFiModule(SensorModule):
    name = "crowd_wifi"

    def run(self) -> None:
        self.log.warning(
            "WiFi crowd-scan module is enabled in config but not yet "
            "implemented in this Tally build — no device_scans rows will be "
            "recorded from this source."
        )
        while not self._stop.is_set():
            time.sleep(5)
