"""
tally_fpp.py — direct FPP playlist triggering for Tally's own "FPP
Triggers" Setup card (per-event playlist/cooldown/play-timeout).

Until now that card saved config that nothing read: the daemon logged
events to the DB and published them to MQTT, but never called FPP to
actually start a playlist, despite the Setup page presenting it as if it
worked. This module is what makes it real.

The GET /api/command/... call shape is ported directly from
fpp-sled-mailbox's proven FPPPlayer class (same author) — POST returns
500 on FPP 10.x, GET with path-based arguments is what actually works.
Unlike SLED's blocking play_file()/wait-for-completion model (SLED
drives continuous playback of its own show), Tally's events are
occasional and don't need the daemon to block waiting for a video to
finish, so this is fire-and-forget:
  - start a named playlist, non-blocking
  - enforce each trigger's own cooldown_s before firing again
  - after play_timeout_s, if FPP is STILL on that same playlist, call
    Stop Now so control returns to FPP's own Scheduler — a safety net
    for a playlist that doesn't end on its own (e.g. left on repeat by
    mistake), not the normal path

Per the project's own documented design ("Defers to FPP's native
Scheduler for show hours — triggers only fire while a show
playlist/schedule is running", see README.md and the Setup page's FPP
Triggers card): a trigger does not fire unless FPP is actively playing
something right now, even if the trigger itself is enabled — so
vehicle/crowd detection outside show hours never interrupts whatever FPP
is or isn't doing. Fails closed: if FPP's status can't be read at all,
nothing fires — a trigger should never fire just because a status check
timed out.

Checked via /api/fppd/status's status_name == "playing", NOT
scheduler.status — confirmed on real hardware (192.168.0.51) that
scheduler.status only reads "playing" for a Scheduler-initiated show;
a playlist started any other way (manually via the API, which is
indistinguishable from how another integration or a builder's own
button might start one) reports scheduler.status == "manual" while
status_name is still correctly "playing". Gating on scheduler.status
alone would have silently blocked every trigger during exactly the kind
of "something is actively playing" state this feature exists to react
to. status_name reflects "is FPP playing something" regardless of what
started it, which is the actually-correct question to ask here.
"""
from __future__ import annotations

import json
import logging
import time
import urllib.parse
import urllib.request
from typing import Any, Dict, Optional, Tuple

log = logging.getLogger("tally.fpp")

_API = "http://localhost"


def _cmd(command: str, args: Optional[list] = None) -> Optional[str]:
    try:
        parts = [urllib.parse.quote(str(command), safe="")]
        for a in (args or []):
            parts.append(urllib.parse.quote(str(a), safe=""))
        url = f"{_API}/api/command/" + "/".join(parts)
        with urllib.request.urlopen(url, timeout=3) as r:
            return r.read().decode()
    except Exception as exc:
        log.warning("FPP command %r failed: %s", command, exc)
        return None


def _status() -> Optional[dict]:
    try:
        with urllib.request.urlopen(f"{_API}/api/fppd/status", timeout=3) as r:
            return json.loads(r.read())
    except Exception as exc:
        log.debug("FPP status check failed: %s", exc)
        return None


def is_show_active() -> bool:
    status = _status()
    if not status:
        return False
    return status.get("status_name") == "playing"


def current_playlist_name() -> str:
    status = _status()
    if not status:
        return ""
    return (status.get("current_playlist", {}) or {}).get("playlist", "")


def start_playlist(name: str) -> None:
    if not name:
        return
    log.info("start playlist: %s", name)
    _cmd("Start Playlist", [name, "false"])


def stop_now() -> None:
    log.info("stop (play_timeout_s watchdog)")
    _cmd("Stop Now")


class TriggerFirer:
    """Owns per-trigger cooldown and play-timeout state for the daemon's
    lifetime. State is in-memory only and resets on restart — same as
    the rest of the daemon's ephemeral runtime state (e.g. ld2410.py's
    _ParkedState); a missed cooldown window across a restart is harmless,
    unlike losing a logged event would be."""

    def __init__(self, cfg: Dict[str, Any]) -> None:
        self.cfg = cfg
        self._last_fired: Dict[str, float] = {}
        # trigger_key -> (playlist_name, fire_time, play_timeout_s)
        self._active: Dict[str, Tuple[str, float, float]] = {}
        self._crowd_above = False

    def fire(self, trigger_key: str) -> None:
        triggers = self.cfg.get("triggers", {}) or {}
        t = triggers.get(trigger_key, {}) or {}
        if not t.get("enabled"):
            return
        playlist = t.get("playlist", "")
        if not playlist:
            log.debug("trigger %s enabled but no playlist configured — skipping", trigger_key)
            return

        cooldown_s = float(t.get("cooldown_s", 30))
        now = time.time()
        last = self._last_fired.get(trigger_key, 0.0)
        remaining = cooldown_s - (now - last)
        if remaining > 0:
            log.debug("trigger %s suppressed — %.1fs left of %ds cooldown",
                       trigger_key, remaining, cooldown_s)
            return

        if not is_show_active():
            log.debug("trigger %s skipped — no show currently running "
                       "(FPP Scheduler status != 'playing')", trigger_key)
            return

        play_timeout_s = float(t.get("play_timeout_s", 60))
        start_playlist(playlist)
        self._last_fired[trigger_key] = now
        self._active[trigger_key] = (playlist, now, play_timeout_s)
        log.info("[FPP Trigger] %s -> playlist '%s' (cooldown=%ds timeout=%ds)",
                  trigger_key, playlist, cooldown_s, play_timeout_s)

    def check_crowd_threshold(self, adjusted_count: int) -> None:
        """Fires 'crowd_threshold' only on the upward crossing, not on
        every scan while the count stays above threshold — otherwise a
        crowd sitting above the line for an hour would attempt to fire
        (and get suppressed by cooldown, but still spam debug logs and
        redundant is_show_active() checks) on every single scan."""
        cs_cfg = self.cfg.get("crowd_scan", {}) or {}
        threshold = int(cs_cfg.get("crowd_threshold", 0))
        if threshold <= 0:
            return
        if adjusted_count >= threshold:
            if not self._crowd_above:
                self._crowd_above = True
                self.fire("crowd_threshold")
        else:
            self._crowd_above = False

    def check_timeouts(self) -> None:
        """Call periodically from the daemon's own event-loop tick — no
        dedicated thread needed, this is cheap and no-ops when nothing is
        active. See module docstring for why this exists."""
        if not self._active:
            return
        now = time.time()
        expired = [key for key, (_, fire_time, timeout_s) in self._active.items()
                   if (now - fire_time) >= timeout_s]
        if not expired:
            return
        current = current_playlist_name()
        for key in expired:
            playlist, _, timeout_s = self._active.pop(key)
            if current == playlist:
                log.warning(
                    "[FPP Trigger] %s: playlist '%s' still playing after its %.0fs "
                    "play_timeout_s — stopping so the Scheduler can resume",
                    key, playlist, timeout_s,
                )
                stop_now()
