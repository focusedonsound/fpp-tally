"""
dht11.py — Tally's DHT11 temperature/humidity module.

Not in the original project spec (which calls for a BME280, I2C) -- added
because the sensor actually available to test against on real hardware is
a DHT11 (single-wire digital protocol, not I2C, despite sometimes ending
up wired to a header pin labeled for an I2C function like "SCL1" -- that
label describes the pin's alternate function, not what's actually talking
over it). Same adafruit_dht library and polling approach fpp-sled-mailbox
already uses for its own (currently unconfigured) DHT11 support, so this
carries no new hardware-support risk beyond what's already proven there.

Shares the same `environment` event kind and `environment` DB table as
the BME280 module (bme280.py) -- only one of the two should be enabled at
a time in practice (they're reporting the same kind of reading), but
nothing stops both from running if someone genuinely has both sensors.

DHT11 read failures (checksum/timing errors) are common and expected --
the protocol is bit-banged over a single wire with tight timing tolerances,
so an occasional bad read is normal, not a fault condition. Logged at
debug level and skipped, not treated as an error worth surfacing loudly.
"""
from __future__ import annotations

import time
from typing import Optional

from .base import SensorModule

try:
    import adafruit_dht
    import board
    DHT_AVAILABLE = True
except (ImportError, NotImplementedError):
    DHT_AVAILABLE = False
    adafruit_dht = None  # type: ignore
    board = None  # type: ignore


def _c_to_f(celsius: float) -> float:
    return celsius * 9.0 / 5.0 + 32.0


class DHT11Module(SensorModule):
    name = "dht11"

    def run(self) -> None:
        if not DHT_AVAILABLE:
            self.log.warning(
                "adafruit_dht/board not importable — DHT11 module idle. "
                "Install the optional pip dependency (see fpp_install.sh)."
            )
            while not self._stop.is_set():
                time.sleep(5)
            return

        dht_cfg = self.cfg.get("dht11", {}) or {}
        pin_num = int(dht_cfg.get("pin", 4))
        poll_interval_s = max(5, int(dht_cfg.get("interval_s", 60)))

        board_pin_name = f"D{pin_num}"
        if not hasattr(board, board_pin_name):
            self.log.warning("GPIO%d is not a valid Blinka board pin on this platform — module idle", pin_num)
            while not self._stop.is_set():
                time.sleep(5)
            return

        try:
            dht_pin = getattr(board, board_pin_name)
            sensor = adafruit_dht.DHT11(dht_pin, use_pulseio=False)
        except Exception as exc:
            self.log.warning("could not initialize DHT11 on GPIO%d: %s — module idle", pin_num, exc)
            while not self._stop.is_set():
                time.sleep(5)
            return

        self.log.info("DHT11 module running: GPIO%d interval=%ds", pin_num, poll_interval_s)

        while not self._stop.is_set():
            try:
                temp_c = sensor.temperature
                humidity_pct = sensor.humidity
                if temp_c is not None and humidity_pct is not None:
                    self._emit(kind="environment", temperature_f=_c_to_f(temp_c), humidity_pct=humidity_pct)
                    self.log.debug("[DHT11] %.1f°F %.1f%%", _c_to_f(temp_c), humidity_pct)
            except RuntimeError as exc:
                # Expected/routine: DHT11 checksum or timing failures happen
                # regularly with this protocol. Not worth more than a debug
                # line -- logging every one at warning level would just be
                # noise on a healthy sensor.
                self.log.debug("[DHT11] read error (normal, will retry): %s", exc)
            except Exception as exc:
                self.log.warning("[DHT11] unexpected read failure: %s", exc)

            slept = 0.0
            while slept < poll_interval_s and not self._stop.is_set():
                time.sleep(min(1.0, poll_interval_s - slept))
                slept += 1.0
