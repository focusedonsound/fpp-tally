"""
thermal.py — Tally's MLX90640 thermal-array module (Option 2).

Design per the project spec section 8 ("Thermal module"):
  1. Frame differencing / blob detection on the 32x24 grid.
  2. Centroid tracking across frames.
  3. Direction: classify by which frame edge the centroid enters/exits from.
  4. Parked detection: a blob present but not crossing an edge for longer
     than parked_timeout_s logs "parked" with dwell duration; its eventual
     departure still logs a normal directional "pass" on top of that.
  5. Speed estimate (optional, best-effort): derived geometrically from the
     configured mount height/angle/distance, not measured directly.

⚠️ Not yet validated against real MLX90640 hardware — no thermal sensor
has been available to test against during development (see project spec
section 13, "Known Risks"). The frame math, blob detection, and I2C
plumbing below are real, not a stub, and are unit-tested against
synthetic frames (see the test harness used during development), but
treat the thresholds/geometry as a starting point to tune once real
hardware is wired up, not as pre-calibrated values.

Only tracks one blob (one vehicle) at a time — the largest connected
component above threshold each frame. A second simultaneous target in the
same zone is not distinguished from the first; that's a documented
limitation, not an oversight, given the reference build's single-lane
entrance zone.
"""
from __future__ import annotations

import math
import time
from typing import List, Optional, Tuple

from .base import SensorModule

try:
    import board
    import busio
    import adafruit_mlx90640
    MLX_AVAILABLE = True
except (ImportError, NotImplementedError):
    # NotImplementedError: Blinka's board module raises this on platforms
    # with no recognized board (e.g. developing/testing off-Pi).
    MLX_AVAILABLE = False
    board = None  # type: ignore
    busio = None  # type: ignore
    adafruit_mlx90640 = None  # type: ignore

GRID_COLS = 32
GRID_ROWS = 24
GRID_SIZE = GRID_COLS * GRID_ROWS


def find_blobs(mask: List[bool], min_size: int) -> List[Tuple[float, float, int]]:
    """4-connected component labeling over the 32x24 grid.

    Returns a list of (centroid_row, centroid_col, size) for every blob at
    least min_size pixels, largest first. Grid is small enough (768 cells)
    that a plain flood fill per frame is cheap.
    """
    visited = [False] * GRID_SIZE
    blobs: List[Tuple[float, float, int]] = []

    for start in range(GRID_SIZE):
        if visited[start] or not mask[start]:
            continue
        stack = [start]
        visited[start] = True
        cells: List[int] = []
        while stack:
            idx = stack.pop()
            cells.append(idx)
            r, c = divmod(idx, GRID_COLS)
            for dr, dc in ((-1, 0), (1, 0), (0, -1), (0, 1)):
                nr, nc = r + dr, c + dc
                if 0 <= nr < GRID_ROWS and 0 <= nc < GRID_COLS:
                    nidx = nr * GRID_COLS + nc
                    if not visited[nidx] and mask[nidx]:
                        visited[nidx] = True
                        stack.append(nidx)
        if len(cells) >= min_size:
            sum_r = sum(cells[i] // GRID_COLS for i in range(len(cells)))
            sum_c = sum(cells[i] % GRID_COLS for i in range(len(cells)))
            n = len(cells)
            blobs.append((sum_r / n, sum_c / n, n))

    blobs.sort(key=lambda b: -b[2])
    return blobs


class _BackgroundModel:
    """Slow exponential-moving-average background estimate, updated only
    at pixels NOT currently part of a foreground blob — a lingering
    vehicle must not get absorbed into the background just because it sits
    still for a while (that's what parked-detection is for), but ambient
    drift (sun warming the pavement over the day) still needs tracking."""

    ALPHA = 0.05  # background adapts over ~20 frames at pixels that stay background

    def __init__(self) -> None:
        self.bg: Optional[List[float]] = None

    def update(self, frame: List[float], fg_mask: List[bool]) -> None:
        if self.bg is None:
            self.bg = list(frame)
            return
        for i in range(GRID_SIZE):
            if not fg_mask[i]:
                self.bg[i] += self.ALPHA * (frame[i] - self.bg[i])

    def delta(self, frame: List[float]) -> List[float]:
        if self.bg is None:
            return [0.0] * GRID_SIZE
        return [frame[i] - self.bg[i] for i in range(GRID_SIZE)]


class _Track:
    def __init__(self, start_col: float, now: float) -> None:
        self.start_col = start_col
        self.last_col = start_col
        self.start_time = now
        self.last_seen = now
        self.parked_logged = False

    def dwell_s(self, now: float) -> int:
        return int(now - self.start_time)


def _estimate_speed_mps(cols_traveled: float, elapsed_s: float, mount_distance_m: float,
                         fov_deg: float = 110.0) -> Optional[float]:
    """Best-effort geometric speed estimate — see module docstring. Returns
    None rather than a number built on a degenerate elapsed_s (avoids a
    divide-by-near-zero producing a nonsense huge speed)."""
    if elapsed_s < 0.15:
        return None
    half_fov_rad = math.radians(fov_deg / 2.0)
    zone_width_m = 2 * mount_distance_m * math.tan(half_fov_rad)
    meters_per_col = zone_width_m / GRID_COLS
    distance_m = abs(cols_traveled) * meters_per_col
    return distance_m / elapsed_s


class ThermalModule(SensorModule):
    name = "thermal"

    def run(self) -> None:
        if not MLX_AVAILABLE:
            self.log.warning(
                "adafruit_mlx90640/board/busio not importable — thermal "
                "module idle. Install the optional pip dependency (see "
                "fpp_install.sh) and confirm I2C is enabled (raspi-config)."
            )
            while not self._stop.is_set():
                time.sleep(5)
            return

        th_cfg = self.cfg.get("thermal", {}) or {}
        zone = th_cfg.get("zone", "entrance")
        frame_rate_hz = max(1, min(16, int(th_cfg.get("frame_rate_hz", 4))))
        min_blob_size = int(th_cfg.get("min_blob_size", 6))
        delta_threshold_c = float(th_cfg.get("delta_threshold_c", 2.0))
        parked_timeout_s = float(th_cfg.get("parked_timeout_s", 180))
        mount_distance_m = float(th_cfg.get("mount_distance_m", 5.0))
        min_travel_cols = float(th_cfg.get("min_travel_cols", 4))
        flip_direction = bool(th_cfg.get("flip_direction", False))

        zone_cfg = (self.cfg.get("zones", {}) or {}).get(zone, {}) or {}
        label_a = zone_cfg.get("direction_a_label", "Inbound")
        label_b = zone_cfg.get("direction_b_label", "Outbound")

        try:
            i2c = board.I2C()
            sensor = adafruit_mlx90640.MLX90640(i2c)
            sensor.refresh_rate = _closest_refresh_rate(frame_rate_hz)
        except Exception as exc:
            self.log.warning("could not initialize MLX90640: %s — module idle", exc)
            while not self._stop.is_set():
                time.sleep(5)
            return

        self.log.info("Thermal module running: zone=%s frame_rate=%dHz", zone, frame_rate_hz)

        bg = _BackgroundModel()
        frame_buf = [0.0] * GRID_SIZE
        track: Optional[_Track] = None
        frame_period_s = 1.0 / frame_rate_hz
        last_live_write = 0.0

        while not self._stop.is_set():
            loop_start = time.time()
            try:
                sensor.getFrame(frame_buf)
            except (ValueError, OSError) as exc:
                # MLX90640 checksum/read glitches are expected occasionally
                # over I2C — skip this frame rather than tearing down the
                # whole module over one bad read.
                self.log.debug("frame read error (skipped): %s", exc)
                time.sleep(frame_period_s)
                continue

            delta = bg.delta(frame_buf)
            fg_mask = [d >= delta_threshold_c for d in delta]
            bg.update(frame_buf, fg_mask)

            blobs = find_blobs(fg_mask, min_blob_size)
            now = time.time()

            if blobs:
                _, col, _ = blobs[0]
                if track is None:
                    track = _Track(col, now)
                else:
                    track.last_col = col
                    track.last_seen = now
                    if not track.parked_logged and track.dwell_s(now) >= parked_timeout_s:
                        track.parked_logged = True
                        self._emit(
                            kind="vehicle", zone=zone, sensor_source="thermal",
                            event_type="parked", direction=None,
                            dwell_duration_s=track.dwell_s(now), speed_estimate=None,
                        )
                        self.log.info("[Thermal] parked zone=%s dwell=%ds", zone, track.dwell_s(now))
            elif track is not None:
                # Target left the frame this cycle — resolve the completed track.
                cols_traveled = track.last_col - track.start_col
                elapsed = track.last_seen - track.start_time
                if abs(cols_traveled) >= min_travel_cols:
                    went_right = (cols_traveled > 0) != flip_direction
                    direction = label_b if went_right else label_a
                    speed = _estimate_speed_mps(cols_traveled, elapsed, mount_distance_m)
                    self._emit(
                        kind="vehicle", zone=zone, sensor_source="thermal",
                        event_type="pass", direction=direction,
                        dwell_duration_s=None, speed_estimate=speed,
                    )
                    self.log.info("[Thermal] pass zone=%s dir=%s speed=%s",
                                  zone, direction, f"{speed:.1f}m/s" if speed else "n/a")
                else:
                    self.log.debug("[Thermal] track dropped — insufficient travel (%.1f cols)", cols_traveled)
                track = None

            # Live-diagnostics state for the Diagnostics page's thermal
            # grid, throttled independent of frame_rate_hz (a 16Hz sensor
            # doesn't need 16 disk writes/sec for a human-watched view).
            # Ephemeral/overwritten-every-write, never persisted to the DB
            # -- see _write_live_state()'s docstring.
            if (now - last_live_write) >= 0.5:
                last_live_write = now
                self._write_live_state("thermal_live.json", {
                    "zone": zone,
                    "cols": GRID_COLS,
                    "rows": GRID_ROWS,
                    "delta_c": [round(d, 1) for d in delta],
                    "delta_threshold_c": delta_threshold_c,
                    "blobs": [
                        {"row": round(r, 1), "col": round(c, 1), "size": n}
                        for r, c, n in blobs
                    ],
                    "tracking": track is not None,
                    "track_dwell_s": track.dwell_s(now) if track is not None else None,
                })

            elapsed_loop = time.time() - loop_start
            time.sleep(max(0.0, frame_period_s - elapsed_loop))


def _closest_refresh_rate(hz: int):
    """Map a requested frame rate to the nearest rate the MLX90640 actually
    supports (0.5, 1, 2, 4, 8, 16, 32, 64 Hz)."""
    if adafruit_mlx90640 is None:
        return None
    table = {
        1: adafruit_mlx90640.RefreshRate.REFRESH_1_HZ,
        2: adafruit_mlx90640.RefreshRate.REFRESH_2_HZ,
        4: adafruit_mlx90640.RefreshRate.REFRESH_4_HZ,
        8: adafruit_mlx90640.RefreshRate.REFRESH_8_HZ,
        16: adafruit_mlx90640.RefreshRate.REFRESH_16_HZ,
    }
    closest = min(table.keys(), key=lambda k: abs(k - hz))
    return table[closest]
