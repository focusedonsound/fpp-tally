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

Runs each radar in engineering mode from the moment it connects (a
superset of basic mode — same presence/distance/energy fields the
direction/parked logic already used, plus per-gate energy arrays) so the
Diagnostics page can show exactly what each sensor is currently seeing,
without needing a runtime mode-switch. If putting a unit into engineering
mode fails, it keeps running in basic mode (decode_report_frame fallback)
— direction/parked detection is unaffected either way; only the
Diagnostics page's per-gate detail is unavailable for that unit.
"""
from __future__ import annotations

import json
import os
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

_STATE_DIR = "/home/fpp/media/plugins/fpp-tally/state"
LIVE_STATE_FILE = os.path.join(_STATE_DIR, "ld2410_live.json")


class _RadarReader:
    """Owns one LD2410B's serial port, presence state, and the latest
    decoded report (basic or engineering) for live diagnostics."""

    def __init__(self, side: str, port: str, min_energy: int, log) -> None:
        self.side = side
        self.port = port
        self.min_energy = min_energy
        self.log = log
        self._ser = None
        self._buf = bytearray()
        self.engineering = False
        self.last_report = None  # proto.Ld2410Report | proto.Ld2410EngReport | None
        self.last_report_ts = 0.0

    def open(self) -> bool:
        if not SERIAL_AVAILABLE:
            self.log.warning("pyserial not installed — LD2410B %s disabled", self.side)
            return False
        try:
            self._ser = serial.Serial(self.port, baudrate=256000, timeout=0.1)
            self.log.info("LD2410B %s opened on %s", self.side, self.port)
        except Exception as exc:
            self.log.warning("LD2410B %s: could not open %s: %s", self.side, self.port, exc)
            return False

        self.engineering = self._enable_engineering_mode()
        if self.engineering:
            self.log.info("LD2410B %s: engineering mode enabled (per-gate diagnostics available)", self.side)
        else:
            self.log.info("LD2410B %s: running in basic mode (engineering mode unavailable — "
                           "Diagnostics page will show presence/distance/energy only, no per-gate detail)",
                           self.side)
        return True

    def _enable_engineering_mode(self) -> bool:
        try:
            if not proto.ld2410_enter_config(self._ser):
                return False
            ok = proto.ld2410_enable_eng(self._ser)
            proto.ld2410_exit_config(self._ser)
            self._ser.reset_input_buffer()
            return ok
        except Exception as exc:
            self.log.debug("LD2410B %s: engineering-mode setup failed: %s", self.side, exc)
            return False

    def close(self) -> None:
        if self._ser is not None:
            try:
                self._ser.close()
            except Exception:
                pass

    def poll_present(self) -> Optional[bool]:
        """Returns True/False if a fresh report was decoded, None if no new
        data. Also updates self.last_report for the live-diagnostics writer,
        preferring an engineering-mode decode and falling back to the basic
        decoder (same fallback SLED's own daemon uses) since not every
        frame necessarily carries engineering data even once enabled."""
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
            report = proto.decode_eng_frame(frame) or proto.decode_report_frame(frame)
            if report is None:
                continue
            self.last_report = report
            self.last_report_ts = time.time()
            move_e = report.move_energy
            still_e = getattr(report, "still_energy", None)
            if still_e is None:
                still_e = getattr(report, "static_energy", 0)
            energy = max(move_e, still_e)
            present = report.present and energy >= self.min_energy
        return present


class _ParkedState:
    """Tracks continuous presence; flips to 'parked' after timeout_s."""

    def __init__(self, timeout_s: float) -> None:
        self.timeout_s = timeout_s
        self._since: Optional[float] = None
        self._parked = False

    def on_presence(self, now: float) -> None:
        # Only starts a new episode (sets _since) if one wasn't already
        # running -- must NOT touch _parked here. This is called on every
        # poll iteration where the radar reports presence, not just on the
        # rising edge, so unconditionally resetting _parked to False here
        # immediately un-arms the "already fired" latch check_parked() just
        # set, on the very next iteration -- confirmed on real hardware:
        # produced a fresh "parked" event roughly every poll cycle for the
        # entire remainder of a car sitting still, instead of exactly once.
        # Only on_absence() (presence genuinely ending) may reset _parked.
        if self._since is None:
            self._since = now

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


def _report_to_dict(reader: _RadarReader) -> dict:
    r = reader.last_report
    stale = (time.time() - reader.last_report_ts) > 3.0 if reader.last_report_ts else True
    base = {
        "connected": reader._ser is not None,
        "engineering": reader.engineering,
        "stale": stale,
        "port": reader.port,
    }
    if r is None:
        return {**base, "present": None}
    base["present"] = r.present
    base["target_status"] = r.target_status
    if isinstance(r, proto.Ld2410EngReport):
        base.update({
            "move_dist_cm": r.move_dist_cm,
            "move_energy": r.move_energy,
            "static_dist_cm": r.static_dist_cm,
            "static_energy": r.static_energy,
            "detect_dist_cm": r.detect_dist_cm,
            "max_move_gate": r.max_move_gate,
            "max_static_gate": r.max_static_gate,
            "gate_move_energy": r.gate_move_energy,
            "gate_static_energy": r.gate_static_energy,
        })
    else:
        base.update({
            "move_dist_cm": r.move_dist_cm,
            "move_energy": r.move_energy,
            "static_dist_cm": r.still_dist_cm,
            "static_energy": r.still_energy,
            "detect_dist_cm": r.detect_dist_cm,
            "max_move_gate": None,
            "max_static_gate": None,
            "gate_move_energy": None,
            "gate_static_energy": None,
        })
    return base


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
        last_live_write = 0.0

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

            # Live-diagnostics state, throttled — this is ephemeral working
            # state for the Diagnostics page to poll, overwritten every
            # cycle, never appended to the permanent events history.
            if (now - last_live_write) >= 0.5:
                last_live_write = now
                try:
                    os.makedirs(_STATE_DIR, exist_ok=True)
                    payload = {
                        "zone": zone,
                        "updated_at": now,
                        "A": _report_to_dict(reader_a),
                        "B": _report_to_dict(reader_b),
                    }
                    tmp = LIVE_STATE_FILE + ".tmp"
                    with open(tmp, "w") as f:
                        json.dump(payload, f)
                    os.replace(tmp, LIVE_STATE_FILE)
                except Exception as exc:
                    self.log.debug("live-state write failed: %s", exc)

            time.sleep(0.05)

        reader_a.close()
        reader_b.close()
        # Deliberately NOT deleted here -- the Diagnostics page already
        # treats the report as stale once last_report_ts is more than 3s
        # old (see _report_to_dict), which correctly communicates "no
        # longer live" without needing a race between this cleanup and
        # whatever the page happens to be polling at that exact moment.
