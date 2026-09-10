"""
ld2410.py — Tally's LD2410B radar module (Option 1).

Orchestration logic (dual-radar sequence-window direction detection,
continuous-presence parked timeout) ported from fpp-sled-mailbox's proven
production daemon loop, reimplemented against Tally's own event schema
(zone/sensor_source/event_type/direction/dwell_duration_s columns) instead
of SLED's letter/donation/car event model. See ld2410_protocol.py for the
underlying serial protocol driver.

Two radar units — "A" and "B" — are wired at the two ends of the zone
being watched. Which one fires first determines direction: A-then-B is one
direction, B-then-A is the other. A car that lingers past parked_timeout_s
on a real target is logged as "parked" instead of "pass", and further pass
events are suppressed for that unit until it actually clears.
"""
from __future__ import annotations

import time
from typing import Optional

from .base import SensorModule
from . import ld2410_protocol as proto

try:
    import serial
    SERIAL_AVAILABLE = True
except ImportError:
    SERIAL_AVAILABLE = False
    serial = None  # type: ignore


class _RadarReader:
    """Owns one LD2410B's serial port and presence state."""

    def __init__(self, side: str, port: str, min_energy: int, log) -> None:
        self.side = side
        self.port = port
        self.min_energy = min_energy
        self.log = log
        self._ser = None
        self._buf = bytearray()

    def open(self) -> bool:
        if not SERIAL_AVAILABLE:
            self.log.warning("pyserial not installed — LD2410B %s disabled", self.side)
            return False
        try:
            self._ser = serial.Serial(self.port, baudrate=256000, timeout=0.1)
            self.log.info("LD2410B %s opened on %s", self.side, self.port)
            return True
        except Exception as exc:
            self.log.warning("LD2410B %s: could not open %s: %s", self.side, self.port, exc)
            return False

    def close(self) -> None:
        if self._ser is not None:
            try:
                self._ser.close()
            except Exception:
                pass

    def poll_present(self) -> Optional[bool]:
        """Returns True/False if a fresh report was decoded, None if no new data."""
        if self._ser is None:
            return None
        try:
            chunk = self._ser.read(self._ser.in_waiting or 1)
        except Exception as exc:
            self.log.debug("LD2410B %s read error: %s", self.side, exc)
            return None
        if not chunk:
            return None
        self._buf.extend(chunk)

        present: Optional[bool] = None
        for frame in proto.extract_report_frames(self._buf):
            report = proto.decode_report_frame(frame)
            if report is None:
                continue
            energy = max(report.move_energy, report.still_energy)
            present = report.present and energy >= self.min_energy
        return present


class _ParkedState:
    """Tracks continuous presence; flips to 'parked' after timeout_s."""

    def __init__(self, timeout_s: float) -> None:
        self.timeout_s = timeout_s
        self._since: Optional[float] = None
        self._parked = False

    def on_presence(self, now: float) -> None:
        if self._since is None:
            self._since = now
        self._parked = False

    def on_absence(self) -> None:
        self._since = None
        self._parked = False

    def check_parked(self, now: float) -> bool:
        """Returns True exactly once, the moment the timeout is first crossed."""
        if self._since is None or self._parked:
            return False
        if (now - self._since) >= self.timeout_s:
            self._parked = True
            return True
        return False

    @property
    def dwell_s(self) -> int:
        return int(time.time() - self._since) if self._since is not None else 0

    def allow_trigger(self) -> bool:
        return not self._parked


class LD2410Module(SensorModule):
    name = "ld2410"

    def run(self) -> None:
        ld_cfg = self.cfg.get("ld2410", {}) or {}
        zone = ld_cfg.get("zone", "driveway")
        seq_window_s = float(ld_cfg.get("sequence_window_s", 0.8))
        parked_timeout_s = float(ld_cfg.get("parked_timeout_s", 180))
        min_energy = int(ld_cfg.get("min_energy", 20))
        # Which raw sequence ("A_to_B" or "B_to_A") counts as this zone's
        # configured "direction A" — the reverse sequence is "direction B".
        # Lets a builder wire the two radar units in either physical order
        # without the labels coming out backwards.
        toward_a_seq = ld_cfg.get("toward_reference", "A_to_B")
        zone_cfg = (self.cfg.get("zones", {}) or {}).get(zone, {}) or {}
        label_a = zone_cfg.get("direction_a_label", "Inbound")
        label_b = zone_cfg.get("direction_b_label", "Outbound")

        def label_for(seq: str) -> str:
            return label_a if seq == toward_a_seq else label_b

        a_cfg = ld_cfg.get("A", {}) or {}
        b_cfg = ld_cfg.get("B", {}) or {}
        reader_a = _RadarReader("A", a_cfg.get("port", "/dev/ttyUSB0"), min_energy, self.log)
        reader_b = _RadarReader("B", b_cfg.get("port", "/dev/ttyUSB1"), min_energy, self.log)

        ok_a = reader_a.open()
        ok_b = reader_b.open()
        if not ok_a and not ok_b:
            self.log.error("no LD2410B radar could be opened — module idle")
            return

        parked_a = _ParkedState(parked_timeout_s)
        parked_b = _ParkedState(parked_timeout_s)
        was_present = {"A": False, "B": False}
        t_last = {"A": None, "B": None}
        cooldown_s = 1.5
        last_pass_ts = 0.0

        self.log.info("LD2410B module running: zone=%s A=%s B=%s", zone, ok_a, ok_b)

        while not self._stop.is_set():
            now = time.time()

            for side, reader, parked in (("A", reader_a, parked_a), ("B", reader_b, parked_b)):
                if reader._ser is None:
                    continue
                present = reader.poll_present()
                if present is None:
                    continue

                if present and not was_present[side]:
                    # rising edge
                    t_last[side] = now
                    other = "B" if side == "A" else "A"
                    other_t = t_last[other]
                    if (other_t is not None and 0 < (now - other_t) <= seq_window_s
                            and (now - last_pass_ts) > cooldown_s):
                        seq = f"{other}_to_{side}"
                        direction = label_for(seq)
                        last_pass_ts = now
                        self._emit(
                            kind="vehicle", zone=zone, sensor_source="ld2410b",
                            event_type="pass", direction=direction,
                            dwell_duration_s=None, speed_estimate=None,
                        )
                        self.log.info("[LD2410] pass zone=%s dir=%s", zone, direction)

                if present:
                    parked.on_presence(now)
                    if parked.check_parked(now):
                        self._emit(
                            kind="vehicle", zone=zone, sensor_source="ld2410b",
                            event_type="parked", direction=None,
                            dwell_duration_s=parked.dwell_s, speed_estimate=None,
                        )
                        self.log.info("[LD2410] parked zone=%s side=%s dwell=%ds",
                                      zone, side, parked.dwell_s)
                elif was_present[side]:
                    parked.on_absence()

                was_present[side] = bool(present)

            time.sleep(0.05)

        reader_a.close()
        reader_b.close()
