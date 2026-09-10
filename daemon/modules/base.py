"""
base.py — common interface every Tally sensor module implements.

Each module (LD2410B, thermal, BLE/WiFi crowd scan, BME280) is independently
enabled/disabled via config, and the daemon must not fail to start if an
optional module's hardware is absent — only load/poll modules the builder
has actually enabled (see tally_config.enabled_modules()).

A module runs its own background thread (started by start(), stopped by
stop()) and reports what it finds by putting typed events onto the shared
queue.Queue the daemon hands it at construction time. It never touches the
database, MQTT, or FPP directly — that's the daemon's job, so every module
stays testable/mockable in isolation and a bug in one module's event
formatting can't corrupt another module's writes.

Event dict shape (put onto the queue):
  {"module": "ld2410" | "thermal" | "crowd_ble" | "crowd_wifi" | "bme280",
   "kind":   "vehicle" | "scan" | "environment",
   ...kind-specific fields...}

  kind="vehicle":     zone, sensor_source, event_type ("pass"|"parked"),
                       direction (nullable), dwell_duration_s (nullable),
                       speed_estimate (nullable)
  kind="scan":         source ("ble"|"wifi"), raw_count
  kind="environment":  temperature_f (nullable), humidity_pct (nullable)
"""
from __future__ import annotations

import logging
import queue
import threading
from typing import Any, Dict


class SensorModule:
    name = "base"

    def __init__(self, cfg: Dict[str, Any], event_queue: "queue.Queue[dict]") -> None:
        self.cfg = cfg
        self.events = event_queue
        self.log = logging.getLogger(f"tally.{self.name}")
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

    def start(self) -> None:
        self._stop.clear()
        self._thread = threading.Thread(target=self._run_safe, name=self.name, daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        if self._thread is not None:
            self._thread.join(timeout=5)

    def _run_safe(self) -> None:
        """Wraps run() so one module crashing never takes down the daemon
        process or the other modules' threads."""
        try:
            self.run()
        except Exception:
            self.log.exception("module thread crashed")

    def run(self) -> None:
        raise NotImplementedError

    def _emit(self, **fields: Any) -> None:
        fields.setdefault("module", self.name)
        try:
            self.events.put_nowait(fields)
        except queue.Full:
            self.log.warning("event queue full — dropping event")
