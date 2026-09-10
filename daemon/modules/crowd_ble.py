"""
crowd_ble.py — Tally's BLE crowd/device-estimate module (Option 3a). STUB.

Not yet implemented — see the Tally project spec section 8 ("Crowd/device
scanning module") for the intended design: passive scan for nearby BLE
advertisements via onboard Bluetooth on a configurable interval (default
60s), counting unique identifiers seen in the window, then handed to the
daemon to apply the user-configured offset and floor at zero.

Modern devices randomize BLE identifiers by default, so even once
implemented this is a relative crowd-density indicator, not an exact
headcount — that caveat belongs in the Setup/Reporting page copy, not just
here, so builders don't over-trust the number once it exists.
"""
from __future__ import annotations

import time

from .base import SensorModule


class CrowdBLEModule(SensorModule):
    name = "crowd_ble"

    def run(self) -> None:
        self.log.warning(
            "BLE crowd-scan module is enabled in config but not yet "
            "implemented in this Tally build — no device_scans rows will be "
            "recorded from this source."
        )
        while not self._stop.is_set():
            time.sleep(5)
