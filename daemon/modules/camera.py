"""
camera.py — Tally's camera-assisted classification module.

Auto-tuning ASSIST only, never real-time gating: this module never blocks or
delays a radar count. `ld2410.py` fires a one-shot classify request (see
CLASSIFY_CMD_FILE) at the moment a pass event is emitted, carrying the radar
features (direction, speed, peak gate energy, gates lit) already gathered for
that event. This module answers it, if it can, by labeling whatever the
camera saw at that moment (car/truck/bus/... vs. person/dog/cat/...) and
emitting a "classification" event pairing the label with those same radar
features. The daemon stores that pair in tally_db.py's pass_features table,
and the Diagnostics page correlates enough of them to suggest (never
auto-apply) a min_energy threshold that would have kept every observed
vehicle while excluding every observed non-vehicle.

Model: TensorFlow MobileNet-SSD v1 (COCO classes), run via OpenCV's dnn
module (cv2.dnn.readNetFromTensorflow). NOT tflite-runtime — verified
directly against a real build (.51: Python 3.13, armv7l, Debian trixie) that
no tflite-runtime wheel exists for that combination on either PyPI or
piwheels, while python3-opencv installs and its dnn module works fine on the
same hardware. The model files are downloaded by fpp_install.sh (not
committed to the plugin repo — the frozen graph alone is ~28MB) from
download.tensorflow.org / opencv_extra's testdata; see MODEL_DIR below.

Inference latency: measured directly on a real Pi 3B+ (the reference
hardware) at a consistent ~5.2-5.4s per single-frame classification, with no
warm-up speedup across repeated calls -- this runs synchronously in the same
thread that polls CLASSIFY_CMD_FILE, so it's the real floor on how often a
pass can actually get classified regardless of min_interval_s. That's fine
for this feature (auto-tuning-assist, not real-time gating -- a request that
lands mid-inference is simply dropped, same as any other throttled request,
and the label always lands a few seconds after the pass it belongs to, which
nothing here depends on being instant), but min_interval_s should be set at
or above that real latency, not below it.

Cold-open problem: the existing diagnostics camera snapshot endpoints
(www/diag_snapshot.php) spawn a fresh `ffmpeg` process and re-open the V4L2
device on every single request, which measured at ~3.2s on this hardware
(see README.md) -- far too slow to use per radar event. This module instead
keeps ONE long-lived stream process open for as long as the module runs,
continuously reading its MJPEG stdout in a background thread and keeping
only the single latest decoded frame in memory (never queued, never written
to disk). A classify request then always has a recent, already-warm frame
available with no capture delay.

Two capture backends -- USB webcam and CSI (Raspberry Pi Camera Module) need
genuinely different tooling, confirmed directly on real hardware (a Pi
Camera Module 3 on a desk-test Pi): a CSI sensor's raw /dev/videoN node only
exposes unprocessed Bayer/greyscale formats -- `ffmpeg -f v4l2` (the USB
path, and what www/diag_snapshot.php already uses) cannot pull a JPEG/YUV
frame from it directly, because libcamera does the debayering/ISP work
itself rather than exposing it through a plain v4l2 pipeline. `rpicam-vid
--codec mjpeg -t 0 -o -` (or the older `libcamera-vid` name) is the
equivalent continuous-MJPEG-to-stdout tool for a CSI camera and was verified
to work exactly like the ffmpeg path from this module's point of view:

  "backend": "auto" (default) -- auto-detected once at module start via
  _detect_backend(): if `rpicam-hello`/`libcamera-hello --list-cameras`
  finds a working camera, use the CSI backend; otherwise fall back to the
  USB/ffmpeg backend against `camera.device`. "usb" or "csi" force one
  path explicitly (e.g. a system with both a CSI camera and an unrelated
  USB webcam, where auto-detection would guess wrong).

Verified end-to-end on real CSI hardware (Pi Camera Module 3, Pi 3B):
auto-detection picked the CSI backend correctly, and a real captured frame
correctly classified as "person" (confidence 0.40) with an actual person in
frame. One CSI-specific latency worth knowing: the stream's first usable
frame took ~6-7s to appear after start (sensor mode selection/autoexposure
settling), vs. much faster for a USB webcam -- a one-time cost paid once at
module startup (or stream restart), not per classification, since the
stream stays open and continuously updated afterward.
"""
from __future__ import annotations

import collections
import json
import logging
import os
import shutil
import subprocess
import threading
import time
from typing import List, Optional, Tuple

from .base import SensorModule

try:
    import cv2
    import numpy as np
    CV2_AVAILABLE = True
except ImportError:
    CV2_AVAILABLE = False
    cv2 = None  # type: ignore
    np = None  # type: ignore

_STATE_DIR = "/home/fpp/media/plugins/fpp-tally/state"
LIVE_STATE_FILE = "camera_live.json"
# Diagnostics/ld2410.py -> camera.py request: one-shot file, same IPC shape
# as ld2410.py's own GATE_CMD_FILE. No result file -- ld2410.py never waits
# on this (fire-and-forget), so a slow or failed classification can never
# add latency to a radar pass event or a Diagnostics page load.
CLASSIFY_CMD_FILE = os.path.join(_STATE_DIR, "camera_classify_cmd.json")

_PLUGIN_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MODEL_DIR = os.path.join(_PLUGIN_DIR, "models", "ssd_mobilenet_v1_coco")
_MODEL_PB = os.path.join(MODEL_DIR, "frozen_inference_graph.pb")
_MODEL_PBTXT = os.path.join(MODEL_DIR, "graph.pbtxt")
_MODEL_LABELS = os.path.join(MODEL_DIR, "labels.txt")

_JPEG_SOI = b"\xff\xd8"
_JPEG_EOI = b"\xff\xd9"
# Guards against an unbounded buffer if the stream never produces a clean
# JPEG boundary (e.g. device hiccup mid-frame) -- drop back to the tail
# rather than growing forever.
_MAX_STREAM_BUFFER = 2_000_000

# Shared warm-frame file: the Diagnostics page's camera preview
# (www/diag_snapshot.php) used to cold-open its own ffmpeg on every poll,
# which on real hardware (.51) turned out to actively fight this module's
# persistent stream for exclusive access to the same USB device -- the two
# were never designed to share it, and the preview started failing every
# single poll the moment this module came up. Writing the latest frame
# here lets diag_snapshot.php serve it directly instead of opening the
# device a second time, whenever this module is running.
FRAME_FILE = os.path.join(_STATE_DIR, "camera_frame.jpg")
# How long a frame on disk is trusted as "the module is actively updating
# this" before a reader should fall back to its own capture -- a few
# multiples of the write cadence below, so one merely-slow cycle doesn't
# look stale.
FRAME_FILE_MAX_AGE_S = 5.0
# How long the stream can run with zero frames captured before it's worth
# a loud warning -- generous enough to cover the slowest real case seen
# (CSI camera startup measured at ~6-7s), not just USB.
_NO_FRAME_WARNING_AFTER_S = 15.0


def _load_labels(path: str) -> List[str]:
    try:
        with open(path) as f:
            return [line.strip() for line in f if line.strip()]
    except Exception:
        return []


def _detect_backend(cam_cfg: dict, log: logging.Logger) -> Tuple[str, Optional[str]]:
    """Returns (backend, rpicam_vid_bin). backend is "usb" or "csi";
    rpicam_vid_bin is the CSI capture binary's path (None for "usb").
    See module docstring -- CSI and USB cameras need different capture
    tools entirely, this is not just a device-path difference."""
    forced = cam_cfg.get("backend", "auto")
    if forced in ("usb", "csi"):
        vid_bin = shutil.which("rpicam-vid") or shutil.which("libcamera-vid") if forced == "csi" else None
        return forced, vid_bin

    vid_bin = shutil.which("rpicam-vid") or shutil.which("libcamera-vid")
    hello_bin = shutil.which("rpicam-hello") or shutil.which("libcamera-hello")
    if vid_bin and hello_bin:
        try:
            result = subprocess.run([hello_bin, "--list-cameras"], capture_output=True,
                                     text=True, timeout=5)
            if "Available cameras" in result.stdout and "No cameras available" not in result.stdout:
                log.info("CSI camera detected via %s — using rpicam backend", hello_bin)
                return "csi", vid_bin
        except Exception as exc:
            log.debug("CSI camera auto-detect failed: %s", exc)
    return "usb", None


class CameraModule(SensorModule):
    name = "camera"

    def __init__(self, cfg, event_queue) -> None:
        super().__init__(cfg, event_queue)
        self._frame_lock = threading.Lock()
        self._latest_frame: Optional[bytes] = None
        self._proc: Optional[subprocess.Popen] = None
        self._reader_thread: Optional[threading.Thread] = None
        self._net = None
        self._labels: List[str] = []
        self._last_classify_ts = 0.0
        self._backend = "usb"
        self._vid_bin: Optional[str] = None
        self._stream_started_ts = 0.0
        self._last_frame_ts = 0.0
        self._no_frame_warned = False
        self._frames_read = 0
        self._stderr_tail: "collections.deque[str]" = collections.deque(maxlen=20)
        self._stderr_thread: Optional[threading.Thread] = None

    def run(self) -> None:
        if not CV2_AVAILABLE:
            self.log.error("opencv (python3-opencv) not installed — camera module idle")
            return

        cam_cfg = self.cfg.get("camera", {}) or {}
        device = cam_cfg.get("device") or (self.cfg.get("calibration", {}) or {}).get(
            "camera_device", "/dev/video0")
        width = int(cam_cfg.get("width", 640))
        height = int(cam_cfg.get("height", 480))
        self._backend, self._vid_bin = _detect_backend(cam_cfg, self.log)
        stream_fps = float(cam_cfg.get("stream_fps", 2))
        # Real single-frame inference on the reference Pi 3B+ measured at
        # ~5.2-5.4s (see module docstring) -- 6s default leaves it as the
        # binding throttle rather than a number smaller than reality.
        min_interval_s = float(cam_cfg.get("min_interval_s", 6))
        confidence_threshold = float(cam_cfg.get("confidence_threshold", 0.5))

        if not (os.path.isfile(_MODEL_PB) and os.path.isfile(_MODEL_PBTXT)):
            self.log.error(
                "classification model not found under %s — re-run the plugin "
                "installer to download it (needs network access once). "
                "camera module idle", MODEL_DIR)
            return
        try:
            self._net = cv2.dnn.readNetFromTensorflow(_MODEL_PB, _MODEL_PBTXT)
        except Exception:
            self.log.exception("failed to load classification model — camera module idle")
            return
        self._labels = _load_labels(_MODEL_LABELS)

        self._start_stream(device, stream_fps, width, height)
        self.log.info("camera module running: backend=%s device=%s stream_fps=%s min_interval_s=%s",
                       self._backend, device if self._backend == "usb" else "(csi, auto)",
                       stream_fps, min_interval_s)

        last_restart_attempt = 0.0
        try:
            while not self._stop.is_set():
                now = time.time()
                if self._proc is None or self._proc.poll() is not None:
                    if (now - last_restart_attempt) > 5.0:
                        last_restart_attempt = now
                        # Surface WHY it died, not just that it did --
                        # found on real hardware (.51) that the stream
                        # process can exit near-instantly (lost a device
                        # race to another process) with nothing in the
                        # log explaining it beyond a generic restart
                        # message, which made a real failure look
                        # identical to routine startup.
                        recent_stderr = " | ".join(self._stderr_tail) if self._stderr_tail else "(no stderr captured)"
                        self.log.warning("camera stream process not running (exit=%s) — restarting. Recent output: %s",
                                          self._proc.poll() if self._proc else "never started", recent_stderr)
                        self._start_stream(device, stream_fps, width, height)
                elif (self._last_frame_ts == 0.0
                        and not self._no_frame_warned
                        and (now - self._stream_started_ts) > _NO_FRAME_WARNING_AFTER_S):
                    # Process is alive (so it didn't fail to open the
                    # device outright) but the reader thread has never
                    # produced a single frame -- the exact silent-failure
                    # gap found on real hardware (.51): the stream *looked*
                    # healthy (process running, no exception) while
                    # actually stuck, and nothing said so. Logged once per
                    # stream instance, not repeated every loop tick.
                    self._no_frame_warned = True
                    self.log.warning(
                        "camera stream process has been running %.0fs with zero frames "
                        "captured — device may be contended by another process, or the "
                        "stream format isn't being parsed correctly", now - self._stream_started_ts)

                self._handle_classify_request(min_interval_s, confidence_threshold)
                time.sleep(0.1)
        finally:
            self._stop_stream()

    # ------------------------------------------------------------------
    # Persistent warm MJPEG stream
    # ------------------------------------------------------------------

    def _start_stream(self, device: str, fps: float, width: int, height: int) -> None:
        if self._backend == "csi":
            cmd = [self._vid_bin, "--codec", "mjpeg", "-t", "0", "--nopreview",
                   "--width", str(width), "--height", str(height),
                   "--framerate", str(fps), "-o", "-"]
        else:
            cmd = ["ffmpeg", "-f", "v4l2", "-i", device, "-vf", f"fps={fps}",
                   "-f", "mjpeg", "-q:v", "5", "-"]
        self._stream_started_ts = time.time()
        self._last_frame_ts = 0.0
        self._no_frame_warned = False
        self._stderr_tail.clear()
        try:
            self._proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        except Exception:
            self.log.exception("failed to start camera stream process")
            self._proc = None
            return
        self._reader_thread = threading.Thread(target=self._read_stream, daemon=True)
        self._reader_thread.start()
        self._stderr_thread = threading.Thread(target=self._read_stderr, daemon=True)
        self._stderr_thread.start()

    def _read_stderr(self) -> None:
        # Captured (not discarded) specifically so a real failure -- lost
        # a device race, unsupported format, etc. -- leaves a trail
        # instead of the process just silently going quiet. Kept only as
        # a small ring buffer, not logged line-by-line (ffmpeg/rpicam-vid
        # are both routinely noisy on stderr during normal operation).
        proc = self._proc
        if proc is None or proc.stderr is None:
            return
        try:
            for line in proc.stderr:
                text = line.decode(errors="replace").strip()
                if text:
                    self._stderr_tail.append(text)
        except Exception:
            pass

    def _stop_stream(self) -> None:
        if self._proc is None:
            return
        try:
            self._proc.terminate()
            self._proc.wait(timeout=3)
        except Exception:
            try:
                self._proc.kill()
            except Exception:
                pass
        self._proc = None
        # Stale frame would otherwise sit there looking "fresh enough"
        # (within FRAME_FILE_MAX_AGE_S) to diag_snapshot.php for a few
        # seconds after this module actually stops.
        try:
            os.unlink(FRAME_FILE)
        except FileNotFoundError:
            pass
        except Exception:
            pass

    def _read_stream(self) -> None:
        # Found on real hardware (.51): this loop previously had no
        # try/except at all -- an unhandled exception here (e.g. a
        # BrokenPipeError if the process died mid-read) would silently end
        # the thread with self._latest_frame stuck at None forever, no log
        # line, no crash, nothing. The stream process itself could stay
        # alive and look perfectly healthy in `ps` the whole time, making
        # this genuinely hard to tell apart from "just hasn't gotten a
        # frame yet." Logged explicitly now instead of disappearing.
        proc = self._proc
        if proc is None or proc.stdout is None:
            return
        buf = b""
        try:
            while not self._stop.is_set() and proc.poll() is None:
                chunk = proc.stdout.read(4096)
                if not chunk:
                    break
                buf += chunk
                if len(buf) > _MAX_STREAM_BUFFER:
                    buf = buf[-_MAX_STREAM_BUFFER // 2:]
                start = buf.find(_JPEG_SOI)
                if start == -1:
                    continue
                end = buf.find(_JPEG_EOI, start + 2)
                if end == -1:
                    continue
                frame = buf[start:end + 2]
                buf = buf[end + 2:]
                now = time.time()
                with self._frame_lock:
                    self._latest_frame = frame
                self._last_frame_ts = now
                self._frames_read += 1
                self._write_frame_file(frame)
        except Exception:
            self.log.exception("camera stream reader crashed")
        self.log.debug("camera stream reader exiting (frames_read=%d)", self._frames_read)

    def _write_frame_file(self, frame: bytes) -> None:
        # Shared with www/diag_snapshot.php -- see FRAME_FILE's comment.
        # Best-effort: a failure here must never affect classification,
        # which only ever reads self._latest_frame in memory.
        try:
            tmp = FRAME_FILE + ".tmp"
            with open(tmp, "wb") as f:
                f.write(frame)
            os.replace(tmp, FRAME_FILE)
        except Exception as exc:
            self.log.debug("frame file write failed: %s", exc)

    # ------------------------------------------------------------------
    # Classify-request handling
    # ------------------------------------------------------------------

    def _handle_classify_request(self, min_interval_s: float, confidence_threshold: float) -> None:
        if not os.path.isfile(CLASSIFY_CMD_FILE):
            return
        try:
            with open(CLASSIFY_CMD_FILE) as f:
                req = json.load(f)
            os.unlink(CLASSIFY_CMD_FILE)
        except Exception as exc:
            self.log.debug("classify request read failed: %s", exc)
            return

        now = time.time()
        if (now - self._last_classify_ts) < min_interval_s:
            self.log.debug("classify request throttled (min_interval_s=%.1f)", min_interval_s)
            return

        with self._frame_lock:
            frame = self._latest_frame
        if frame is None:
            # Upgraded from debug -- this is exactly the symptom found on
            # real hardware (.51): a classify request silently producing
            # nothing, with zero trail explaining why. Naturally
            # rate-limited already (one classify request per radar pass,
            # further throttled by min_interval_s), so this can't spam.
            self.log.warning(
                "no camera frame available yet — skipping classify request "
                "(stream running=%s, frames read so far=%d)",
                self._proc is not None and self._proc.poll() is None, self._frames_read)
            return

        self._last_classify_ts = now
        label, confidence = self._classify(frame, confidence_threshold)
        self.log.info("classified pass: label=%s confidence=%s zone=%s direction=%s",
                       label, confidence, req.get("zone"), req.get("direction"))

        self._write_live_state(LIVE_STATE_FILE, {
            "label": label, "confidence": confidence, "ts": now,
        })
        self._emit(
            kind="classification",
            zone=req.get("zone"),
            direction=req.get("direction"),
            speed_estimate=req.get("speed_mps"),
            peak_energy=req.get("peak_energy"),
            gates_lit=req.get("gates_lit"),
            camera_label=label,
            camera_confidence=confidence,
        )

    def _classify(self, frame_bytes: bytes, confidence_threshold: float
                  ) -> Tuple[Optional[str], Optional[float]]:
        arr = np.frombuffer(frame_bytes, dtype=np.uint8)
        img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
        if img is None:
            return None, None
        blob = cv2.dnn.blobFromImage(img, size=(300, 300), swapRB=True, crop=False)
        self._net.setInput(blob)
        out = self._net.forward()

        best_label: Optional[str] = None
        best_conf = 0.0
        for detection in out[0, 0]:
            conf = float(detection[2])
            if conf < confidence_threshold or conf <= best_conf:
                continue
            class_id = int(detection[1])
            label = self._labels[class_id - 1] if 0 < class_id <= len(self._labels) else None
            if label:
                best_label, best_conf = label, conf
        return (best_label, best_conf) if best_label else (None, None)
