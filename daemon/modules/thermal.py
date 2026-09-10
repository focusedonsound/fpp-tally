"""
thermal.py — Tally's MLX90640 thermal-array module (Option 2). STUB.

Not yet implemented — see the Tally project spec section 8 ("Thermal
module") for the intended design: frame differencing / blob detection on
the 32x24 grid, centroid tracking across frames, edge-crossing direction
classification, and a configurable parked_timeout_s for dwell detection.

This stub exists so the module is a real, selectable entry in the
hardware-selection wizard and the daemon's conditional-loading path today,
without pretending to detect anything it can't yet detect. It logs once on
start and does nothing else — it never emits fabricated events, and its
absence/failure never blocks any other module or the daemon itself.
"""
from __future__ import annotations

import time

from .base import SensorModule


class ThermalModule(SensorModule):
    name = "thermal"

    def run(self) -> None:
        self.log.warning(
            "Thermal (MLX90640) module is enabled in config but not yet "
            "implemented in this Tally build — no thermal events will be "
            "recorded. This module will start reporting once the blob "
            "detection / centroid tracking logic ships in a future release."
        )
        # Idle rather than exit immediately, so a future hot-reload of the
        # module list (not yet implemented either) has a live thread to
        # signal instead of finding the module already gone.
        while not self._stop.is_set():
            time.sleep(5)
