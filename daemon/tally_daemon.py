#!/usr/bin/env python3
# =============================================================================
# tally_daemon.py — Tally Vehicle & Crowd Counter daemon (FPP plugin edition)
#
# Architecture: independent systemd service (tally.service), independent
# SQLite database (tally.db) — no dependency on any other FPP plugin, no
# shared code or database with fpp-sled-mailbox.
#
# Modular sensor loading: each sensor module (LD2410B, thermal, BLE/WiFi
# crowd scan, BME280) is its own background thread, independently enabled
# via tally.json's "modules" block. A module with no hardware present (or
# not yet implemented) logs a warning and idles — it never prevents any
# other module, or the daemon itself, from running. This is deliberately
# tested against every enable/disable combination, not just the reference
# build's full hardware set (project spec section 13).
#
# Registration status (see tally_config.is_registered) is a soft gate on
# the web UI ONLY. This daemon starts and logs events regardless of it.
# =============================================================================
from __future__ import annotations

import logging
import os
import queue
import signal
import sys
import threading
import time
from typing import Any, Dict

_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, _SCRIPT_DIR)

from tally_config import load_config, enabled_modules
from tally_db import TallyDB
from tally_ha import TallyHA
from modules.ld2410 import LD2410Module
from modules.thermal import ThermalModule
from modules.crowd_ble import CrowdBLEModule
from modules.crowd_wifi import CrowdWiFiModule
from modules.bme280 import BME280Module
from modules.dht11 import DHT11Module

_LOGDIR = os.environ.get("LOGDIR", "/home/fpp/media/logs")
LOG_FILE = os.path.join(_LOGDIR, "plugin-fpp-tally.log")
_STATE_DIR = "/home/fpp/media/plugins/fpp-tally/state"
PID_FILE = os.path.join(_STATE_DIR, "tally_daemon.pid")
CMD_QUEUE_FILE = os.path.join(_STATE_DIR, "tally_trigger.cmd")

try:
    os.makedirs(_LOGDIR, exist_ok=True)
    os.makedirs(_STATE_DIR, exist_ok=True)
except OSError:
    pass

_handlers: list = []
try:
    _handlers.append(logging.FileHandler(LOG_FILE))
except OSError:
    _handlers.append(logging.StreamHandler(sys.stderr))

logging.basicConfig(
    level=logging.INFO,
    format="[%(asctime)s] [%(name)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    handlers=_handlers,
)
log = logging.getLogger("tally")

MODULE_CLASSES = {
    "ld2410": LD2410Module,
    "thermal": ThermalModule,
    "crowd_ble": CrowdBLEModule,
    "crowd_wifi": CrowdWiFiModule,
    "bme280": BME280Module,
    "dht11": DHT11Module,
}

_shutdown = threading.Event()


def _handle_signal(signum, frame) -> None:
    log.info("Signal %s received — shutting down", signum)
    _shutdown.set()


signal.signal(signal.SIGTERM, _handle_signal)
signal.signal(signal.SIGINT, _handle_signal)


def poll_cmd_queue() -> str | None:
    """Commands: 'trigger_vehicle:<zone>', 'trigger_crowd', 'stop'."""
    if not os.path.isfile(CMD_QUEUE_FILE):
        return None
    try:
        with open(CMD_QUEUE_FILE) as f:
            cmd = f.read().strip()
        os.unlink(CMD_QUEUE_FILE)
        return cmd if cmd else None
    except Exception as exc:
        log.warning("[CmdQueue] read error: %s", exc)
        return None


def _handle_vehicle_event(db: TallyDB, ha: TallyHA, cfg: Dict[str, Any], ev: Dict[str, Any]) -> None:
    zone = ev["zone"]
    db.log_event(
        zone=zone,
        sensor_source=ev.get("sensor_source", ev.get("module", "unknown")),
        event_type=ev["event_type"],
        direction=ev.get("direction"),
        dwell_duration_s=ev.get("dwell_duration_s"),
        speed_estimate=ev.get("speed_estimate"),
    )
    ha.event("vehicle", {"zone": zone, "event_type": ev["event_type"], "direction": ev.get("direction")})
    _publish_zone_counts(db, ha, cfg, zone)


def _publish_zone_counts(db: TallyDB, ha: TallyHA, cfg: Dict[str, Any], zone: str) -> None:
    zone_cfg = (cfg.get("zones", {}) or {}).get(zone, {}) or {}
    label_a = zone_cfg.get("direction_a_label", "Inbound")
    label_b = zone_cfg.get("direction_b_label", "Outbound")

    cars_today = db.count_events_today(zone=zone, event_type="pass")
    cars_total = db.count_events_total(zone=zone, event_type="pass")
    dir_a_today = db.count_events_today(zone=zone, event_type="pass", direction=label_a)
    dir_b_today = db.count_events_today(zone=zone, event_type="pass", direction=label_b)
    parked_today = db.count_events_today(zone=zone, event_type="parked")
    parked_total = db.count_events_total(zone=zone, event_type="parked")

    ha.set_zone_counts(zone, cars_today, cars_total, dir_a_today, dir_b_today,
                        parked_today, parked_total)


def _handle_scan_event(db: TallyDB, ha: TallyHA, cfg: Dict[str, Any], mods_enabled: Dict[str, bool],
                        ev: Dict[str, Any]) -> None:
    source = ev["source"]
    offset = int((cfg.get("crowd_scan", {}) or {}).get("device_offset", 0))
    db.log_scan(source, ev["raw_count"], offset)

    # BLE and WiFi identifiers are unrelated address spaces (a phone's BLE
    # MAC and WiFi probe MAC are randomized independently), so there's no
    # way to actually deduplicate the same physical device appearing in
    # both -- summing the two would double-count every device that shows
    # up on both radios. Taking the max of the two latest readings avoids
    # that double-count while still reflecting whichever radio currently
    # sees more devices, which is the most defensible reading of the
    # spec's "combine/dedupe into a combined source count" given that
    # constraint (see project spec section 8, and the randomization
    # caveat repeated throughout the crowd-scan modules and the UI).
    if mods_enabled.get("crowd_ble") and mods_enabled.get("crowd_wifi"):
        latest_ble = db.latest_scan(source="ble")
        latest_wifi = db.latest_scan(source="wifi")
        if latest_ble and latest_wifi:
            # Pick whichever reading is higher as a single consistent
            # (raw, offset) pair -- maxing raw and adjusted independently
            # could mix two different scans' numbers into a combination
            # neither radio actually reported.
            winner = (latest_ble if latest_ble["adjusted_count"] >= latest_wifi["adjusted_count"]
                      else latest_wifi)
            db.log_scan("combined", winner["raw_count"], winner["offset_applied"])
            ha.set_devices_nearby(winner["adjusted_count"])
            return

    latest = db.latest_scan(source=source)
    if latest:
        ha.set_devices_nearby(latest["adjusted_count"])


def _handle_environment_event(db: TallyDB, ha: TallyHA, ev: Dict[str, Any]) -> None:
    db.log_environment(ev.get("temperature_f"), ev.get("humidity_pct"))
    ha.set_environment(ev.get("temperature_f"), ev.get("humidity_pct"))


def _simulate_vehicle(zone: str, event_queue: "queue.Queue[dict]", cfg: Dict[str, Any]) -> None:
    """Backs the 'Trigger Test Vehicle Event' FPP command / diagnostics
    button — dispatches a synthetic pass event without any real hardware,
    so a builder can verify DB → MQTT → FPP trigger wiring before a single
    sensor is connected."""
    zone_cfg = (cfg.get("zones", {}) or {}).get(zone, {}) or {}
    label_a = zone_cfg.get("direction_a_label", "Inbound")
    module = zone_cfg.get("module", "ld2410")
    source = "ld2410b" if module == "ld2410" else "thermal"
    event_queue.put_nowait({
        "module": module, "kind": "vehicle", "zone": zone, "sensor_source": source,
        "event_type": "pass", "direction": label_a, "dwell_duration_s": None,
        "speed_estimate": None,
    })


def _simulate_crowd_scan(event_queue: "queue.Queue[dict]") -> None:
    event_queue.put_nowait({"module": "crowd_ble", "kind": "scan", "source": "ble", "raw_count": 3})


def main() -> None:
    log.info("=== Tally daemon starting ===")

    with open(PID_FILE, "w") as f:
        f.write(str(os.getpid()))

    cfg = load_config()
    if not cfg.get("enabled", True):
        log.info("Plugin disabled in config — exiting")
        return

    mods_enabled = enabled_modules(cfg)
    log.info("Enabled modules: %s", ", ".join(k for k, v in mods_enabled.items() if v) or "(none)")

    db = TallyDB()
    ha = TallyHA(cfg)

    ha.setup_status_discovery()
    for zone_name, zone_cfg in (cfg.get("zones", {}) or {}).items():
        # Only publish discovery entities for a zone whose owning module is
        # actually enabled — an idle stub module's zone would otherwise show
        # HA entities that never update.
        owning_module = zone_cfg.get("module")
        if owning_module and mods_enabled.get(owning_module):
            ha.setup_zone_discovery(zone_name, zone_cfg.get("direction_a_label", "Inbound"),
                                     zone_cfg.get("direction_b_label", "Outbound"))
            _publish_zone_counts(db, ha, cfg, zone_name)
    if mods_enabled.get("crowd_ble") or mods_enabled.get("crowd_wifi"):
        ha.setup_crowd_discovery()
    if mods_enabled.get("bme280") or mods_enabled.get("dht11"):
        ha.setup_environment_discovery()

    event_queue: "queue.Queue[dict]" = queue.Queue(maxsize=1000)

    modules = []
    for mod_name, mod_enabled in mods_enabled.items():
        if not mod_enabled:
            continue
        cls = MODULE_CLASSES[mod_name]
        mod = cls(cfg, event_queue)
        mod.start()
        modules.append(mod)
        log.info("Started module: %s", mod_name)

    log.info("Tally daemon running (PID=%d)", os.getpid())

    try:
        while not _shutdown.is_set():
            queued = poll_cmd_queue()
            if queued:
                if queued == "stop":
                    log.info("[CmdQueue] STOP — shutting down")
                    _shutdown.set()
                    break
                elif queued.startswith("trigger_vehicle:"):
                    zone = queued.split(":", 1)[1]
                    _simulate_vehicle(zone, event_queue, cfg)
                    log.info("[CmdQueue] simulated vehicle event: zone=%s", zone)
                elif queued == "trigger_crowd":
                    _simulate_crowd_scan(event_queue)
                    log.info("[CmdQueue] simulated crowd scan")

            try:
                ev = event_queue.get(timeout=0.5)
            except queue.Empty:
                continue

            kind = ev.get("kind")
            try:
                if kind == "vehicle":
                    _handle_vehicle_event(db, ha, cfg, ev)
                elif kind == "scan":
                    _handle_scan_event(db, ha, cfg, mods_enabled, ev)
                elif kind == "environment":
                    _handle_environment_event(db, ha, ev)
                else:
                    log.warning("unknown event kind from %s: %r", ev.get("module"), kind)
            except Exception:
                log.exception("failed handling event from %s", ev.get("module"))

    finally:
        log.info("Tally daemon shutting down...")
        for mod in modules:
            mod.stop()
        ha.close()
        db.close()
        try:
            os.unlink(PID_FILE)
        except Exception:
            pass
        log.info("=== Tally daemon stopped ===")


if __name__ == "__main__":
    try:
        main()
    except Exception:
        import traceback
        msg = traceback.format_exc()
        try:
            log.critical("UNHANDLED EXCEPTION — daemon exiting:\n%s", msg)
        except Exception:
            sys.stderr.write(f"Tally daemon UNHANDLED EXCEPTION:\n{msg}\n")
        sys.exit(1)
