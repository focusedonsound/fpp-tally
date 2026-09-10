"""
ld2410_protocol.py — HLK-LD2410B radar serial protocol driver.

Ported directly from fpp-sled-mailbox's sled_ld2410.py (same author,
PolyForm Noncommercial licensed) per the Tally project spec section 4: this
is the proven low-level protocol implementation, not SLED-specific business
logic, so it's reused as-is rather than reinvented. The sequence-window
direction-detection and parked-timeout orchestration built on top of it
lives in ld2410.py and is written fresh against Tally's own event/DB model.

Protocol reference: HLK-LD2410B serial protocol v1.05
  Baud: 256000, 8N1
  Data frame: F4 F3 F2 F1 [len u16le] [type u8] [payload] F8 F7 F6 F5
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import List, Optional

HDR_RPT = b"\xF4\xF3\xF2\xF1"
FTR_RPT = b"\xF8\xF7\xF6\xF5"

STATUS_NONE = 0x00
STATUS_MOVING = 0x01
STATUS_STATIC = 0x02
STATUS_BOTH = 0x03


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
