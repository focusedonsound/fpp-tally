"""
bme280.py — Tally's BME280 temperature/humidity module (Option 4). STUB.

Not yet implemented — see the Tally project spec section 8. Intended design:
poll the sensor over I2C (default address 0x76, no conflict with the
MLX90640's 0x33 on the same bus — no multiplexer needed) on a configurable
interval (default 5-10 min), log to the `environment` table. Purely
contextual data for the Reporting page today; a documented future
enhancement is using ambient temperature to auto-tune the thermal module's
blob sensitivity threshold, since colder nights increase thermal contrast.
"""
from __future__ import annotations

import time

from .base import SensorModule


class BME280Module(SensorModule):
    name = "bme280"

    def run(self) -> None:
        self.log.warning(
            "BME280 module is enabled in config but not yet implemented in "
            "this Tally build — no environment readings will be recorded."
        )
        while not self._stop.is_set():
            time.sleep(5)
