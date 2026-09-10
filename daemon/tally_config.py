"""
tally_config.py — config load helpers and hardware-tier module resolution.

Tally's daemon must gracefully handle any combination of enabled modules,
including single-module builds (Option 1 only, Option 2 only, or neither
vehicle sensor with only crowd/environment modules running). This module is
the single place that decides "which modules does this install actually
want running" so tally_daemon.py doesn't duplicate that logic.
"""
from __future__ import annotations

import json
import logging
import os
from typing import Any, Dict

CONFIG_FILE = "/home/fpp/media/config/tally.json"

_LOGGER = logging.getLogger("tally.config")

# Every module key this build of Tally knows about. A module absent from
# config['modules'] is treated as disabled (fail-closed), not an error.
KNOWN_MODULES = ("ld2410", "thermal", "crowd_ble", "crowd_wifi", "bme280")


def load_config() -> Dict[str, Any]:
    try:
        with open(CONFIG_FILE) as f:
            return json.load(f)
    except FileNotFoundError:
        _LOGGER.warning("Config file not found (%s) — using empty config", CONFIG_FILE)
        return {}
    except json.JSONDecodeError as exc:
        _LOGGER.error("Config file is invalid JSON (%s): %s — using empty config",
                       CONFIG_FILE, exc)
        return {}


def save_config(cfg: Dict[str, Any]) -> None:
    """Atomic write — used by the daemon itself only for state it owns
    (e.g. registration status echoed back from the license server). The
    Setup page writes config through its own PHP save endpoint, not this."""
    tmp = CONFIG_FILE + ".tmp"
    with open(tmp, "w") as f:
        json.dump(cfg, f, indent=2)
    os.replace(tmp, CONFIG_FILE)


def enabled_modules(cfg: Dict[str, Any]) -> Dict[str, bool]:
    """Which sensor modules this install wants running, defaulting every
    unknown/missing key to False (fail-closed) rather than assuming the
    reference build's full hardware set is present."""
    declared = cfg.get("modules", {}) or {}
    return {name: bool(declared.get(name, False)) for name in KNOWN_MODULES}


def is_registered(cfg: Dict[str, Any]) -> bool:
    """Registration is a soft gate on the web UI ONLY (Setup/Reporting pages).
    The daemon must never consult this to decide whether to run — see
    daemon_start() in callbacks.sh, which starts the daemon unconditionally
    whenever the plugin itself is enabled."""
    return bool((cfg.get("registration") or {}).get("registered", False))
