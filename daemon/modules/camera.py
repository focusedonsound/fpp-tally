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
                if self._proc is None or self._proc.poll() is not None:
                    now = time.time()
                    if (now - last_restart_attempt) > 5.0:
                        last_restart_attempt = now
                        self.log.warning("camera stream process not running — restarting")
                        self._start_stream(device, stream_fps, width, height)

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
        try:
            self._proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
        except Exception:
            self.log.exception("failed to start camera stream process")
            self._proc = None
            return
        self._reader_thread = threading.Thread(target=self._read_stream, daemon=True)
        self._reader_thread.start()

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

    def _read_stream(self) -> None:
        proc = self._proc
        if proc is None or proc.stdout is None:
            return
        buf = b""
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
            with self._frame_lock:
                self._latest_frame = buf[start:end + 2]
            buf = buf[end + 2:]
        self.log.debug("camera stream reader exiting")

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
            self.log.debug("no camera frame available yet — skipping classify request")
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
