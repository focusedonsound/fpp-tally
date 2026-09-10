"""
bme280.py — Tally's BME280 temperature/humidity module (Option 4).

Polls the sensor over I2C on a configurable interval (default 10 min per
the project spec), logs to the `environment` table. Purely contextual data
for the Reporting page today — no detection logic. Shares the I2C bus with
the MLX90640 (Option 2) at a different address (0x76/0x77 vs 0x33), no
conflict, no multiplexer needed, per the project spec's hardware notes.

Not validated against real BME280 hardware — none has been available
during development. The C→F conversion and the poll loop's error handling
are unit-tested against a fake sensor object (see the test harness used
during development).
"""
from __future__ import annotations

import time
from typing import Optional

from .base import SensorModule

try:
    import board
    # The adafruit-circuitpython-bme280 package restructured its API into a
    # basic/advanced split at v2.6 -- support both layouts rather than
    # pinning to one, since there's no real hardware here to confirm which
    # version ends up installed on a given Pi OS image.
    try:
        import adafruit_bme280.basic as adafruit_bme280
    except ImportError:
        import adafruit_bme280
    BME280_AVAILABLE = True
except (ImportError, NotImplementedError):
    BME280_AVAILABLE = False
    board = None  # type: ignore
    adafruit_bme280 = None  # type: ignore


def _c_to_f(celsius: float) -> float:
    return celsius * 9.0 / 5.0 + 32.0


class BME280Module(SensorModule):
    name = "bme280"

    def run(self) -> None:
        if not BME280_AVAILABLE:
            self.log.warning(
                "adafruit_bme280/board not importable — BME280 module idle. "
                "Install the optional pip dependency (see fpp_install.sh) and "
                "confirm I2C is enabled (raspi-config)."
            )
            while not self._stop.is_set():
                time.sleep(5)
            return

        bme_cfg = self.cfg.get("bme280", {}) or {}
        poll_interval_s = max(10, int(bme_cfg.get("poll_interval_s", 600)))
        address_str = str(bme_cfg.get("i2c_address", "0x76"))

        try:
            address = int(address_str, 16)
        except ValueError:
            self.log.warning("invalid i2c_address %r — falling back to 0x76", address_str)
            address = 0x76

        try:
            i2c = board.I2C()
            sensor = adafruit_bme280.Adafruit_BME280_I2C(i2c, address=address)
        except Exception as exc:
            self.log.warning("could not initialize BME280 at %s: %s — module idle",
                              address_str, exc)
            while not self._stop.is_set():
                time.sleep(5)
            return

        self.log.info("BME280 module running: address=%s interval=%ds", address_str, poll_interval_s)

        while not self._stop.is_set():
            try:
                temp_f, humidity_pct = self._read(sensor)
                self._emit(kind="environment", temperature_f=temp_f, humidity_pct=humidity_pct)
                self.log.debug("[BME280] %.1f°F %.1f%%", temp_f, humidity_pct)
            except Exception as exc:
                # A single bad I2C read (bus contention with the MLX90640,
                # a transient glitch) must not kill the polling loop --
                # just skip this reading and try again next interval.
                self.log.warning("BME280 read failed: %s", exc)

            slept = 0.0
            while slept < poll_interval_s and not self._stop.is_set():
                time.sleep(min(1.0, poll_interval_s - slept))
                slept += 1.0

    @staticmethod
    def _read(sensor) -> tuple[float, float]:
        """Split out for testability — takes anything with .temperature (°C)
        and .relative_humidity (%) attributes, real sensor or fake."""
        temp_f = _c_to_f(sensor.temperature)
        humidity_pct = sensor.relative_humidity
        return temp_f, humidity_pct
