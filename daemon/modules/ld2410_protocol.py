"""
ld2410_protocol.py — HLK-LD2410B radar serial protocol driver.

Ported directly from fpp-sled-mailbox's sled_ld2410.py (same author,
PolyForm Noncommercial licensed) per the Tally project spec section 4: this
is the proven low-level protocol implementation, not SLED-specific business
logic, so it's reused as-is rather than reinvented. The sequence-window
direction-detection and parked-timeout orchestration built on top of it
lives in ld2410.py and is written fresh against Tally's own event/DB model.

Engineering-mode support (per-gate energy arrays, and the config-mode
commands needed to turn it on) was intentionally left out of the initial
port to keep v0.1.0's scope minimal -- added back here for the
Diagnostics page's live radar readout, which needs the same per-gate
detail SLED's own diagnostic mode already shows.

Protocol reference: HLK-LD2410B serial protocol v1.05
  Baud: 256000, 8N1
  Config frame:  FD FC FB FA [len u16le] [cmd u16le] [params] 04 03 02 01
  Data frame:    F4 F3 F2 F1 [len u16le] [type u8] [payload] F8 F7 F6 F5
"""
from __future__ import annotations

import struct
import time
from collections import Counter
from dataclasses import dataclass, field
from typing import List, Optional

HDR_RPT = b"\xF4\xF3\xF2\xF1"
FTR_RPT = b"\xF8\xF7\xF6\xF5"
HDR_CFG = b"\xFD\xFC\xFB\xFA"
FTR_CFG = b"\x04\x03\x02\x01"

STATUS_NONE = 0x00
STATUS_MOVING = 0x01
STATUS_STATIC = 0x02
STATUS_BOTH = 0x03

NUM_GATES = 9  # gates 0..8, each ~0.75 m -> 0..6.75 m total


@dataclass
class Ld2410Report:
    """Basic presence report (normal operating mode)."""
    target_status: int = STATUS_NONE
    move_dist_cm: Optional[int] = None
    move_energy: int = 0
    still_dist_cm: Optional[int] = None
    still_energy: int = 0
    detect_dist_cm: Optional[int] = None

    @property
    def present(self) -> bool:
        return self.target_status != STATUS_NONE


@dataclass
class Ld2410EngReport:
    """Engineering-mode report — includes per-gate energy arrays. Used by
    the Diagnostics page's live radar readout; direction/parked detection
    in ld2410.py only reads the fields it shares with Ld2410Report."""
    target_status: int = STATUS_NONE
    move_dist_cm: int = 0
    move_energy: int = 0
    static_dist_cm: int = 0
    static_energy: int = 0
    detect_dist_cm: int = 0
    max_move_gate: int = 8
    max_static_gate: int = 8
    gate_move_energy: List[int] = field(default_factory=lambda: [0] * NUM_GATES)
    gate_static_energy: List[int] = field(default_factory=lambda: [0] * NUM_GATES)

    @property
    def present(self) -> bool:
        return self.target_status != STATUS_NONE


def _u16le(b: bytes, i: int) -> int:
    return int(b[i]) | (int(b[i + 1]) << 8)



# The ONLY two data-length values a real report frame from this 9-gate
# sensor ever declares -- confirmed against the manufacturer's own
# protocol document (HLK-LD2410 Serial Communication Protocol V1.02,
# Table 8/9/11/13's worked examples): 13 for a basic-mode frame, 35 for
# an engineering-mode frame. Deliberately exact, not a loose ceiling:
# confirmed on real hardware that length-field corruption isn't always a
# clean single-bit flip (one captured example had 0x23 corrupted to
# 0x93 -- three bits differ, not one), so a generous range still let
# obviously-bogus lengths through and produced a "frame" with no real
# footer at the end of it. Any other declared length means the header
# match was noise or the length byte(s) got mangled -- either way, not
# a real frame worth trying to parse.
_VALID_REPORT_DATA_LENS = (13, 35)


def extract_report_frames(buf: bytearray) -> List[bytes]:
    """Extract and remove all complete data frames from buf. Returns raw frame bytes.

    Frame boundaries are found via the declared length field (bytes 4-5,
    right after the header: "F4 F3 F2 F1 [len u16le] [type] [payload]
    F8 F7 F6 F5" per the protocol reference above), not by searching for
    the next literal footer match. Confirmed on real hardware this
    matters: the same USB-serial bit-7 parity corruption already worked
    around in _cfg_ack() also occasionally flips a bit in the footer
    bytes, and a naive `buf.find(FTR_RPT, ...)` search then skips right
    past the corrupted footer and keeps scanning until it finds the
    *next* frame's footer instead -- silently merging two real frames
    into one oversized, unparseable blob. Trusting the length field
    instead means one corrupted footer byte no longer corrupts frame
    boundary detection for anything after it."""
    frames: List[bytes] = []
    while True:
        start = buf.find(HDR_RPT)
        if start < 0:
            if len(buf) > 4096:
                del buf[:-64]
            break
        if start > 0:
            del buf[:start]

        if len(buf) < 6:
            break  # need header(4) + length(2) before we know the frame size

        # Bit-7 masked on both length bytes -- confirmed on real hardware
        # the same USB-serial parity corruption hits the length field
        # itself, not just report_type/status/footer (observed 0x23
        # arriving as 0xa3). Safe to mask unconditionally: every real
        # frame this protocol produces is well under 64 bytes, so the
        # true length never legitimately sets bit 7 on either byte.
        # Masking recovers a single-bit-7 corruption (0xa3 -> 0x23), but
        # not every corruption is that clean -- confirmed on real
        # hardware a length byte can have multiple bits wrong at once
        # (0x23 corrupted to 0x93, three bits differ), which masking
        # alone turns into a different-but-still-wrong value (19) that a
        # loose ceiling would have waved through. The exact-value check
        # below is the real defense; masking is just a first pass that
        # catches the common case cheaply.
        data_len = (buf[4] & 0x7F) | ((buf[5] & 0x7F) << 8)
        if data_len not in _VALID_REPORT_DATA_LENS:
            # Not a length this protocol ever legitimately produces --
            # the "header" bytes we matched were noise, or the length
            # field is corrupted beyond what bit-7 masking recovers.
            # Drop just the header and resync on the next occurrence
            # rather than accepting a frame that was never real.
            del buf[:4]
            continue

        total_needed = 4 + 2 + data_len + 4
        if len(buf) < total_needed:
            break  # wait for more bytes

        frames.append(bytes(buf[:total_needed]))
        del buf[:total_needed]
    return frames


def decode_report_frame(frame: bytes) -> Optional[Ld2410Report]:
    """Parse a basic (non-engineering) data frame. Returns Ld2410Report or None."""
    # No footer content check here -- confirmed on real hardware the
    # USB-serial corruption that hits report_type/status/the length
    # field isn't confined to bit 7 (one captured footer differed by bit
    # 3 instead), so any fixed-bit-mask tolerance still rejects some
    # genuinely good frames. Framing is already authoritative from the
    # declared length field (extract_report_frames), and the field
    # plausibility bounds below are the real defense against garbage
    # slipping through -- the footer bytes were never load-bearing once
    # both of those are in place. Header match is still worth keeping;
    # it's how extract_report_frames found this frame in the first
    # place, and staying defensive costs nothing.
    if not frame.startswith(HDR_RPT):
        return None
    body = frame[4:-4]
    if len(body) < 6:
        return None

    offset = 3
    while offset < len(body):
        if body[offset] == 0xAA:
            break
        offset += 1
    else:
        return None

    j = offset + 1
    if j >= len(body):
        return None
    # Bit-7 masked -- confirmed on real hardware the same USB-serial
    # parity corruption _cfg_ack() already works around also occasionally
    # flips this byte (observed target_status arriving as 0x82 instead of
    # 0x02). Legitimate values are only 0x00-0x03, so bit 7 is never
    # real data here -- safe to mask unconditionally, unlike the energy/
    # distance fields below which legitimately use the full byte range.
    status = body[j] & 0x7F
    j += 1

    if j + 9 > len(body):
        return None

    move_dist = _u16le(body, j)
    move_energy = body[j + 2]
    j += 3
    still_dist = _u16le(body, j)
    still_energy = body[j + 2]
    j += 3
    detect_dist = _u16le(body, j)
    j += 2

    if not (0 <= move_dist <= 9000 and 0 <= still_dist <= 9000
            and 0 <= move_energy <= 100 and 0 <= still_energy <= 100):
        return None

    return Ld2410Report(
        target_status=status,
        move_dist_cm=move_dist if move_dist > 0 else None,
        move_energy=move_energy,
        still_dist_cm=still_dist if still_dist > 0 else None,
        still_energy=still_energy,
        detect_dist_cm=detect_dist if detect_dist > 0 else None,
    )


def decode_eng_frame(frame: bytes) -> Optional[Ld2410EngReport]:
    """Parse an engineering-mode data frame (per-gate energy arrays for
    both moving and stationary targets). Returns None if the frame isn't
    actually an engineering-type frame (report_type != 0x01) or is too
    short -- callers should fall back to decode_report_frame() in that
    case, same as SLED's own daemon does."""
    # See decode_report_frame's matching comment for why there's no
    # footer content check here.
    if not frame.startswith(HDR_RPT):
        return None
    body = frame[4:-4]

    if len(body) < 3:
        return None
    # Bit-7 masked -- same known USB-serial parity corruption as status
    # below (confirmed on real hardware: a genuine 0x01 engineering-type
    # byte was observed arriving as 0x81). Without this mask, a perfectly
    # good engineering frame silently fails this check and falls back to
    # decode_report_frame() every time it happens -- which is exactly
    # the "negotiated engineering mode... but came back in basic format"
    # symptom that motivated this fix.
    report_type = body[2] & 0x7F

    offset = 3
    while offset < len(body):
        if body[offset] == 0xAA:
            break
        offset += 1
    else:
        return None

    j = offset + 1
    if j + 2 + NUM_GATES + NUM_GATES > len(body):
        return None

    # See decode_report_frame's matching comment -- legitimate range is
    # 0x00-0x03, bit 7 is never real data.
    status = body[j] & 0x7F
    j += 1

    if j + 8 > len(body):
        return None
    move_dist = _u16le(body, j)
    move_energy = body[j + 2]
    j += 3
    static_dist = _u16le(body, j)
    static_energy = body[j + 2]
    j += 3
    detect_dist = _u16le(body, j)
    j += 2

    if report_type != 0x01 or j + 2 + NUM_GATES + NUM_GATES > len(body):
        return None

    # Same plausibility bound decode_report_frame applies -- confirmed on
    # real hardware: occasional USB-serial noise garbles a distance field
    # to a bogus ~32000+ cm value (max u16 range) while the rest of the
    # frame still passes the header/footer/length checks. Reject the
    # whole frame rather than surface a nonsense reading on the
    # Diagnostics page; the caller falls back to the next frame/poll.
    if not (0 <= move_dist <= 9000 and 0 <= static_dist <= 9000):
        return None

    max_move_gate = body[j]
    j += 1
    max_static_gate = body[j]
    j += 1
    gate_move = list(body[j:j + NUM_GATES])
    j += NUM_GATES
    gate_static = list(body[j:j + NUM_GATES])

    return Ld2410EngReport(
        target_status=status,
        move_dist_cm=move_dist,
        move_energy=move_energy,
        static_dist_cm=static_dist,
        static_energy=static_energy,
        detect_dist_cm=detect_dist,
        max_move_gate=max_move_gate,
        max_static_gate=max_static_gate,
        gate_move_energy=gate_move,
        gate_static_energy=gate_static,
    )


# =============================================================================
# Config-mode commands -- needed only to switch the radar into engineering
# mode at startup (see ld2410.py). No gate-sensitivity tuning here; that's
# out of scope for Tally today.
# =============================================================================

def _pack_cfg_frame(cmd: int, params: bytes = b"") -> bytes:
    payload = struct.pack("<H", cmd) + params
    return HDR_CFG + struct.pack("<H", len(payload)) + payload + FTR_CFG


def _cfg_ack(rsp: Optional[bytes]) -> bool:
    """True if the response payload carries a success ACK. Standard layout:
    [cmd_lo][cmd_hi][status_lo][status_hi] (4+ bytes); success = status
    bytes both 0x00. Bit-7 masked to tolerate USB-serial parity corruption
    seen on some adapters, same as SLED's driver."""
    return (
        rsp is not None
        and len(rsp) >= 4
        and (rsp[2] & 0x7F) == 0
        and (rsp[3] & 0x7F) == 0
    )


def _read_cfg_response(ser, timeout: float = 0.5) -> Optional[bytes]:
    deadline = time.time() + timeout
    buf = bytearray()
    while time.time() < deadline:
        chunk = ser.read(ser.in_waiting or 1)
        if chunk:
            buf.extend(chunk)

        start = buf.find(HDR_CFG)
        if start < 0:
            if len(buf) > 512:
                buf.clear()
            continue
        if start > 0:
            del buf[:start]

        if len(buf) < 6:
            continue

        data_len = (buf[4] & 0x7F) | ((buf[5] & 0x7F) << 8)
        if data_len > 256:
            del buf[:4]
            continue

        total_needed = 4 + 2 + data_len + 4
        if len(buf) < total_needed:
            continue

        payload = bytes(buf[6:6 + data_len])
        del buf[:total_needed]
        return payload

    return None


def ld2410_enter_config(ser) -> bool:
    """Switch radar into configuration mode. Must be called before
    ld2410_enable_eng(). Returns True on ACK."""
    ser.write(_pack_cfg_frame(0x00FE))
    ser.flush()
    time.sleep(0.05)
    try:
        ser.reset_input_buffer()
    except Exception:
        pass

    for _ in range(3):
        ser.write(_pack_cfg_frame(0x00FF, b"\x01\x00"))
        ser.flush()
        rsp = _read_cfg_response(ser, timeout=1.5)
        if _cfg_ack(rsp):
            return True
        time.sleep(0.2)
        try:
            ser.reset_input_buffer()
        except Exception:
            pass
    return False


def ld2410_exit_config(ser) -> bool:
    """Exit configuration mode and return to data-output mode."""
    ser.write(_pack_cfg_frame(0x00FE))
    rsp = _read_cfg_response(ser)
    return _cfg_ack(rsp)


def ld2410_enable_eng(ser) -> bool:
    """Enable engineering mode (streams per-gate energy in every data
    frame). Radar must already be in config mode."""
    ser.write(_pack_cfg_frame(0x0062))
    rsp = _read_cfg_response(ser)
    return _cfg_ack(rsp)


def ld2410_disable_eng(ser) -> bool:
    """Disable engineering mode, returning to basic (summary-only) data
    frames. Radar must already be in config mode.

    Not currently called anywhere in ld2410.py -- the module always
    attempts to enable engineering mode and never turns it back off, so
    this exists for completeness (mirrors fpp-sled-mailbox's own
    ld2410_disable_eng) and for ad-hoc testing. Confirmed on real
    hardware that engineering mode is a setting the radar itself
    persists across reconnects -- simply not re-sending the enable
    command on a fresh connection does NOT revert a radar that was
    already enabled in a prior session; only this explicit disable call
    does."""
    ser.write(_pack_cfg_frame(0x0063))
    rsp = _read_cfg_response(ser)
    return _cfg_ack(rsp)


# =============================================================================
# Per-gate sensitivity -- the same native radar feature the official HLK
# config tool exposes, not something layered on top in software. This
# is a real threshold the radar's own firmware applies when deciding
# target_status for each frame (per the manufacturer protocol doc,
# sections 2.2.4 and 2.2.7): "Only when the detected target energy value
# (range 0-100) is greater than the sensitivity value will it be
# determined that the target exists." Setting per-gate sensitivity here
# filters at the source, before data ever reaches Tally -- stronger than
# any single min_energy threshold applied after the fact in ld2410.py,
# and (per the doc) persists in the radar's own memory across power
# cycles, so this is a deliberate one-time-per-adjustment action, not
# something re-sent on every connect.
# =============================================================================

def _read_gate_config_once(ser) -> Optional[dict]:
    """Byte layout of the ACK payload (as returned by _read_cfg_response,
    i.e. starting right after the length field): cmd echo(2) + status(2)
    + 0xAA head marker(1) + max_distance_gate(1) + configured max moving
    gate(1) + configured max static gate(1) + motion sensitivity per
    gate 0-8(9) + static sensitivity per gate 0-8(9) + no-person
    duration seconds, u16le(2).

    Bit-7 masked on every field except no_person_duration_s -- confirmed
    on real hardware (192.168.0.51) the same USB-serial parity
    corruption already worked around throughout this file also hits this
    response: configured_max_static_gate arrived as 128 (should be 1-8)
    and multiple sensitivity values arrived with bit 7 set (158 instead
    of 30, 143 instead of 15). Every masked field's legitimate range
    (1-8 for gate indices, 0-100 for sensitivity) never needs that bit.
    no_person_duration_s is deliberately left unmasked -- its documented
    range is 0-65535 seconds, so bit 7 can be genuine data there."""
    ser.write(_pack_cfg_frame(0x0061))
    payload = _read_cfg_response(ser)
    if payload is None or len(payload) < 28:
        return None
    if (payload[2] & 0x7F) != 0 or (payload[3] & 0x7F) != 0:
        return None  # ACK status != success
    return {
        "max_distance_gate": payload[5] & 0x7F,
        "configured_max_moving_gate": payload[6] & 0x7F,
        "configured_max_static_gate": payload[7] & 0x7F,
        "motion_sensitivity": [b & 0x7F for b in payload[8:17]],
        "static_sensitivity": [b & 0x7F for b in payload[17:26]],
        "no_person_duration_s": payload[26] | (payload[27] << 8),
    }


def ld2410_read_gate_config(ser) -> Optional[dict]:
    """Read the radar's current per-gate sensitivity configuration
    (command 0x0061). Returns None if too few readable samples came back
    to form a real answer. Radar must already be in config mode.

    Collects up to 6 structurally-successful reads (retrying a
    structurally-failed attempt -- timeout, truncated response -- same
    reasoning as ld2410_enter_config's own retry loop) and takes a
    per-field majority vote across them, rather than trusting the first
    read that merely parses without error, or even requiring one lucky
    exact match between two full reads in a row.

    Confirmed on real hardware this per-field approach is needed, not
    just a retry: individual sensitivity bytes corrupt independently of
    each other and land on values that are still plausible on their own
    (12 or 8 instead of the real 20 -- all valid 0-100 sensitivities), so
    range-checking can't catch it the way the data-frame decoders'
    distance bounds can. Requiring two FULL 21-field reads to match
    exactly turned out to be too strict in practice -- with ~21
    independent fields each occasionally wrong, the odds of any two
    complete reads agreeing on all of them are worse than the odds of
    each individual field being correct most of the time. Voting per
    field uses that partial correctness instead of discarding it."""
    samples = []
    for attempt in range(6):
        cfg = _read_gate_config_once(ser)
        if cfg is not None:
            samples.append(cfg)
        time.sleep(0.15)
        try:
            ser.reset_input_buffer()
        except Exception:
            pass

    if len(samples) < 2:
        return None

    def _vote(values):
        return Counter(values).most_common(1)[0][0]

    result = {}
    for key in ("max_distance_gate", "configured_max_moving_gate", "configured_max_static_gate", "no_person_duration_s"):
        result[key] = _vote(s[key] for s in samples)
    for key in ("motion_sensitivity", "static_sensitivity"):
        result[key] = [_vote(s[key][g] for s in samples) for g in range(NUM_GATES)]
    return result


def _set_gate_sensitivity_once(ser, gate: int, motion_sensitivity: int, static_sensitivity: int) -> bool:
    """Command value layout: gate word(2, always 0x0000) + gate value
    u32le(4) + motion sensitivity word(2, always 0x0001) + motion
    sensitivity value u32le(4) + static sensitivity word(2, always
    0x0002) + static sensitivity value u32le(4)."""
    params = (
        struct.pack("<H", 0x0000) + struct.pack("<I", gate)
        + struct.pack("<H", 0x0001) + struct.pack("<I", motion_sensitivity)
        + struct.pack("<H", 0x0002) + struct.pack("<I", static_sensitivity)
    )
    ser.write(_pack_cfg_frame(0x0064, params))
    rsp = _read_cfg_response(ser)
    return _cfg_ack(rsp)


def ld2410_set_gate_sensitivity(ser, gate: int, motion_sensitivity: int, static_sensitivity: int) -> bool:
    """Configure sensitivity (0-100, higher = less sensitive -- the
    radar only reports a target once its energy exceeds this value) for
    one gate, or every gate at once if gate == 0xFFFF (command 0x0064).
    Radar must already be in config mode.

    Retries up to 3 times -- same reasoning as ld2410_read_gate_config:
    confirmed on real hardware this class of config-mode round trip
    (distinct from the continuous data-frame stream) fails outright
    intermittently (timeout, truncated response), which only a retry
    fixes. Writing a real setting to the radar's persistent memory makes
    a false failure here worse than most -- worth the extra attempts."""
    for attempt in range(3):
        if _set_gate_sensitivity_once(ser, gate, motion_sensitivity, static_sensitivity):
            return True
        time.sleep(0.15)
        try:
            ser.reset_input_buffer()
        except Exception:
            pass
    return False
