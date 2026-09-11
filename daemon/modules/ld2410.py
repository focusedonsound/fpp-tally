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
# Diagnostics page's per-gate sensitivity read/write request/response --
# a request file written by diag_tune.php, handled inside this module's
# own run loop (the only place that owns the live, already-open serial
# connection -- a second connection attempt from elsewhere would
# conflict with it) and answered via the response file.
GATE_CMD_FILE = os.path.join(_STATE_DIR, "ld2410_gate_cmd.json")
GATE_RESULT_FILE = os.path.join(_STATE_DIR, "ld2410_gate_result.json")
# Fire-and-forget request to camera.py at pass-emit time -- see that
# module's docstring. Never read back a result here: classification is an
# auto-tuning-assist side effect only, and must never add latency to (or
# depend on the camera even being enabled for) a real radar pass event.
CLASSIFY_CMD_FILE = os.path.join(_STATE_DIR, "camera_classify_cmd.json")

# How long a reader can go without a single successfully-decoded report
# before the module assumes the radar itself has gone quiet (not the USB
# device -- that would show up as a read exception, which is handled
# separately) and tries a full close/reopen/re-handshake. Long enough
# that normal inter-report gaps and one-off garbled frames (already
# filtered by decode_eng_frame's plausibility bound) never trigger it;
# short enough that a real hang gets noticed and self-healed well within
# a single show cue rather than needing a manual daemon restart.
_RECONNECT_TIMEOUT_S = 15.0


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
        self._last_reconnect_attempt = 0.0

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

    def read_gate_config(self) -> Optional[dict]:
        """Read the radar's current per-gate sensitivity, for the
        Diagnostics page's gate-tuning UI to show real values instead of
        letting a builder set numbers blind. Briefly interrupts normal
        report streaming (config mode) -- fine for a rare, deliberate
        user action, not something called on a schedule."""
        if self._ser is None:
            return None
        try:
            if not proto.ld2410_enter_config(self._ser):
                return None
            cfg = proto.ld2410_read_gate_config(self._ser)
            proto.ld2410_exit_config(self._ser)
            self._ser.reset_input_buffer()
            return cfg
        except Exception as exc:
            self.log.warning("LD2410B %s: read gate config failed: %s", self.side, exc)
            return None

    def set_gate_sensitivity(self, gate: int, motion_sensitivity: int, static_sensitivity: int) -> bool:
        """Write per-gate sensitivity to the radar's own persistent
        memory (command 0x0064) -- the same native filtering the
        official HLK config tool uses, applied at the sensor itself
        rather than in software after the fact. Persists across power
        cycles per the manufacturer protocol doc, so this is a
        deliberate one-time action, never called automatically."""
        if self._ser is None:
            return False
        try:
            if not proto.ld2410_enter_config(self._ser):
                return False
            ok = proto.ld2410_set_gate_sensitivity(self._ser, gate, motion_sensitivity, static_sensitivity)
            proto.ld2410_exit_config(self._ser)
            self._ser.reset_input_buffer()
            return ok
        except Exception as exc:
            self.log.warning("LD2410B %s: set gate sensitivity failed: %s", self.side, exc)
            return False

    def close(self) -> None:
        if self._ser is not None:
            try:
                self._ser.close()
            except Exception:
                pass

    def reconnect(self) -> bool:
        """Close and reopen the port, redoing the engineering-mode
        handshake. Called by the module's watchdog when a previously-
        working reader has gone silent for _RECONNECT_TIMEOUT_S.

        Confirmed on real hardware this silence happens with NO exception
        and NO empty read ever logged: the USB-serial adapter stays
        enumerated (poll_present()'s self._ser.read() keeps succeeding),
        but the radar itself stops putting anything on the UART, so
        there's nothing for read()'s try/except to catch. A watchdog on
        report *age* -- not on read errors -- is the only way to notice
        this at all, which is exactly why it went unexplained before:
        the daemon looked alive (live-state file still updating on
        schedule) with no warning anywhere about why the radar itself had
        gone quiet."""
        self._last_reconnect_attempt = time.time()
        self.log.warning("LD2410B %s: no valid report in over %.0fs — reconnecting",
                          self.side, _RECONNECT_TIMEOUT_S)
        self.close()
        self._ser = None
        ok = self.open()
        if ok:
            # Give the reconnect a fresh timeout window rather than
            # immediately re-triggering the watchdog before the first
            # post-reconnect frame has had a chance to arrive.
            self.last_report_ts = time.time()
        return ok

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
        # "engineering" reflects the ACTUAL type of the most recent decoded
        # report, not reader.engineering (which only records whether the
        # one-time enable-engineering-mode handshake succeeded at connect
        # time). Those can disagree: a unit that negotiated engineering
        # mode fine can still intermittently (or permanently, e.g. a loose
        # connection) stream basic-format frames afterward -- decode_eng_frame
        # returning None falls back to decode_report_frame in poll_present(),
        # silently. Using the stale connect-time flag here produced exactly
        # the confusing real-hardware symptom this replaced: the page
        # labeled a unit "engineering mode" while showing no per-gate data
        # for it, because the label and the data came from two different
        # points in time. engineering_negotiated is kept alongside for
        # troubleshooting -- "never negotiated" and "negotiated but not
        # currently streaming it" are different problems.
        "engineering": isinstance(r, proto.Ld2410EngReport),
        "engineering_negotiated": reader.engineering,
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


def _peak_energy_and_gates(reader_a: "_RadarReader", reader_b: "_RadarReader",
                            min_energy: int) -> tuple[int, int]:
    """Rough per-pass radar "signature" for the camera-assisted tuning
    feature: the single highest gate-energy reading and how many gates (across
    both units) currently read at/above min_energy -- not a physical
    measurement, just enough signal to let pass_features correlate a
    camera-confirmed vehicle/non-vehicle label against radar strength."""
    peak = 0
    gates_lit = 0
    for reader in (reader_a, reader_b):
        r = reader.last_report
        if r is None:
            continue
        gate_energy = getattr(r, "gate_move_energy", None)
        if gate_energy:
            for e in gate_energy:
                if e is None:
                    continue
                peak = max(peak, e)
                if e >= min_energy:
                    gates_lit += 1
        else:
            # Basic-mode fallback -- only the overall move_energy is
            # available, no per-gate breakdown.
            me = getattr(r, "move_energy", None)
            if me is not None:
                peak = max(peak, me)
                if me >= min_energy:
                    gates_lit += 1
    return peak, gates_lit


def _write_classify_request(zone: str, direction: str, speed_mps, peak_energy: int,
                             gates_lit: int, log) -> None:
    try:
        os.makedirs(_STATE_DIR, exist_ok=True)
        payload = {
            "zone": zone, "direction": direction, "speed_mps": speed_mps,
            "peak_energy": peak_energy, "gates_lit": gates_lit, "ts": time.time(),
        }
        tmp = CLASSIFY_CMD_FILE + ".tmp"
        with open(tmp, "w") as f:
            json.dump(payload, f)
        os.replace(tmp, CLASSIFY_CMD_FILE)
    except Exception as exc:
        log.debug("classify request write failed: %s", exc)


def _write_gate_result(payload: dict) -> None:
    try:
        os.makedirs(_STATE_DIR, exist_ok=True)
        tmp = GATE_RESULT_FILE + ".tmp"
        payload.setdefault("ts", time.time())
        with open(tmp, "w") as f:
            json.dump(payload, f)
        os.replace(tmp, GATE_RESULT_FILE)
    except Exception:
        pass


def _handle_gate_cmd_request(reader_a: "_RadarReader", reader_b: "_RadarReader", log) -> None:
    """Services one pending per-gate sensitivity read/write request from
    the Diagnostics page (see GATE_CMD_FILE). Checked every loop tick --
    cheap (one os.path.isfile call) in the overwhelmingly common case
    where no request is pending; only does real work, briefly pausing
    that side's report streaming for the config-mode round trip, when a
    builder has actually asked for one. One-shot: the request file is
    removed immediately so a slow response can't cause it to be
    processed twice."""
    if not os.path.isfile(GATE_CMD_FILE):
        return
    try:
        with open(GATE_CMD_FILE) as f:
            req = json.load(f)
        os.unlink(GATE_CMD_FILE)
    except Exception as exc:
        log.warning("[GateCmd] failed to read request: %s", exc)
        return

    side = req.get("side")
    reader = reader_a if side == "A" else reader_b if side == "B" else None
    if reader is None or reader._ser is None:
        _write_gate_result({"ok": False, "side": side, "message": f"Side {side} not connected"})
        return

    action = req.get("action")
    if action == "read":
        cfg = reader.read_gate_config()
        if cfg is None:
            _write_gate_result({"ok": False, "side": side, "message": "Read failed — check the connection and try again"})
        else:
            _write_gate_result({"ok": True, "side": side, "action": "read", **cfg})
        log.info("[GateCmd] %s: read gate config -> %s", side, "ok" if cfg else "failed")
    elif action == "write":
        gate = int(req.get("gate", 0xFFFF))
        motion = int(req.get("motion", 0))
        static = int(req.get("static", 0))
        ok = reader.set_gate_sensitivity(gate, motion, static)
        _write_gate_result({
            "ok": ok, "side": side, "action": "write", "gate": gate,
            "motion": motion, "static": static,
            "message": "Saved to the radar's own memory" if ok else "Write failed — check the connection and try again",
        })
        log.info("[GateCmd] %s: set gate=%s motion=%d static=%d -> %s", side, gate, motion, static, "ok" if ok else "failed")
    else:
        _write_gate_result({"ok": False, "side": side, "message": f"Unknown action {action!r}"})


class LD2410Module(SensorModule):
    name = "ld2410"

    def run(self) -> None:
        ld_cfg = self.cfg.get("ld2410", {}) or {}
        zone = ld_cfg.get("zone", "driveway")
        seq_window_s = float(ld_cfg.get("sequence_window_s", 0.8))
        parked_timeout_s = float(ld_cfg.get("parked_timeout_s", 180))
        min_energy = int(ld_cfg.get("min_energy", 20))
        # Physical distance between the two radar units, used to turn the
        # measured A<->B trigger gap into a real speed estimate (distance
        # / time) instead of just a pass/fail check against
        # sequence_window_s. Default matches the reference mailbox
        # mounting (24in) -- every install's actual spacing differs, so
        # this must be configurable, not assumed.
        sensor_separation_m = float(ld_cfg.get("sensor_separation_cm", 61)) / 100.0
        # Gate index (0-8) marking the boundary between the "near lane"
        # (closer to the sensors, e.g. traffic leaving the property) and
        # "far lane" (e.g. incoming traffic on the far side of the road)
        # for the Diagnostics page's lane view. Purely a display split,
        # not used in any detection/trigger logic -- every install's
        # actual mailbox-to-road geometry differs, so this needs to be
        # tuned per install, not assumed.
        lane_split_gate = int(ld_cfg.get("lane_split_gate", 4))
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
        # Most recent pass, for the Diagnostics page's lane view -- live
        # display only, this doesn't replace the DB as the source of
        # truth for history, it just avoids that page needing a second
        # endpoint/DB query just to show "what just happened."
        last_pass_info: dict | None = None

        self.log.info("LD2410B module running: zone=%s A=%s B=%s", zone, ok_a, ok_b)

        while not self._stop.is_set():
            now = time.time()

            _handle_gate_cmd_request(reader_a, reader_b, self.log)

            for side, reader, parked in (("A", reader_a, parked_a), ("B", reader_b, parked_b)):
                if reader._ser is None:
                    continue

                # Watchdog: a reader that has received at least one report
                # before but has gone quiet for too long gets a full
                # reconnect attempt. Throttled by _last_reconnect_attempt
                # so a reconnect that doesn't actually fix anything
                # retries at most once per timeout window instead of
                # hammering the port every 0.05s tick.
                if (reader.last_report_ts
                        and (now - reader.last_report_ts) > _RECONNECT_TIMEOUT_S
                        and (now - reader._last_reconnect_attempt) > _RECONNECT_TIMEOUT_S):
                    reader.reconnect()
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
                        # Real speed = the known physical gap between the
                        # two units / how long it actually took to trigger
                        # the second one, not just "did it happen within
                        # the window" -- previously this elapsed time was
                        # computed for the pass/fail check and then thrown
                        # away. Guarded the same way thermal.py's own
                        # _estimate_speed_mps is: a near-zero elapsed time
                        # (sensor noise, not a real transit) would produce
                        # a nonsense huge speed, so skip it instead.
                        elapsed_s = now - other_t
                        speed_mps = sensor_separation_m / elapsed_s if elapsed_s >= 0.05 else None
                        self._emit(
                            kind="vehicle", zone=zone, sensor_source="ld2410b",
                            event_type="pass", direction=direction,
                            dwell_duration_s=None, speed_estimate=speed_mps,
                        )
                        self.log.info("[LD2410] pass zone=%s dir=%s transit=%.2fs speed=%s",
                                      zone, direction, elapsed_s,
                                      f"{speed_mps:.2f}m/s" if speed_mps else "n/a")
                        last_pass_info = {
                            "direction": direction,
                            "transit_s": round(elapsed_s, 2),
                            "speed_mps": round(speed_mps, 2) if speed_mps else None,
                            "ts": now,
                        }
                        # Auto-tuning-assist only -- fire-and-forget, never
                        # waited on, and skipped entirely unless the camera
                        # module is actually enabled so no stale request
                        # file lingers for a module that isn't running to
                        # ever consume it. See camera.py's docstring.
                        if (self.cfg.get("modules", {}) or {}).get("camera"):
                            peak_energy, gates_lit = _peak_energy_and_gates(
                                reader_a, reader_b, min_energy)
                            _write_classify_request(
                                zone, direction, speed_mps, peak_energy, gates_lit, self.log)

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
                        "lane_split_gate": lane_split_gate,
                        "last_pass": last_pass_info,
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
