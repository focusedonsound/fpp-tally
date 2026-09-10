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


def extract_report_frames(buf: bytearray) -> List[bytes]:
    """Extract and remove all complete data frames from buf. Returns raw frame bytes."""
    frames: List[bytes] = []
    while True:
        start = buf.find(HDR_RPT)
        if start < 0:
            if len(buf) > 4096:
                del buf[:-64]
            break
        if start > 0:
            del buf[:start]
        end = buf.find(FTR_RPT, 4)
        if end < 0:
            break
        end += len(FTR_RPT)
        frames.append(bytes(buf[:end]))
        del buf[:end]
    return frames


def decode_report_frame(frame: bytes) -> Optional[Ld2410Report]:
    """Parse a basic (non-engineering) data frame. Returns Ld2410Report or None."""
    if not (frame.startswith(HDR_RPT) and frame.endswith(FTR_RPT)):
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
    status = body[j]
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
    if not (frame.startswith(HDR_RPT) and frame.endswith(FTR_RPT)):
        return None
    body = frame[4:-4]

    if len(body) < 3:
        return None
    report_type = body[2]

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

    status = body[j]
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
