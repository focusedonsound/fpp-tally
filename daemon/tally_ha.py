"""
tally_ha.py — MQTT + Home Assistant Discovery publisher for Tally.

Reimplemented independently of fpp-sled-mailbox's ha.py (same architectural
pattern — MQTT client + HA discovery + LWT — but its own topic layout and
entity set, matching the Tally project spec section 9). No shared code or
imports between the two plugins.

Topic layout (base defaults to "tally"):
  {base}/status                        ← LWT: "online" / "offline"
  {base}/status/{metric}                ← retained state values
  {base}/event/{kind}                    ← transient event JSON payloads

HA discovery:
  homeassistant/{component}/{dev_id}_{obj_id}/config   ← retained config
  (cleared by remove_discovery() for clean plugin uninstall)

Entities published (per enabled zone/module — see spec section 9):
  Cars Today / Cars Total (per zone, + combined if multiple zones enabled)
  Inbound Today / Outbound Today (using configured direction labels)
  Parked Today / Parked Total / Parked Conversion %
  Estimated Devices Nearby
  Temperature / Humidity (if BME280 enabled)
  Daemon status (LWT-backed)
"""
from __future__ import annotations

import json
import logging
import socket
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

try:
    from paho.mqtt import client as mqtt
    PAHO_AVAILABLE = True
except ImportError:
    PAHO_AVAILABLE = False  # type: ignore
    mqtt = None  # type: ignore

import urllib.request

_LOGGER = logging.getLogger("tally.ha")


def _fpp_setting(name: str) -> str:
    """Read one FPP setting via the settings API -- the raw settings file's
    format is not a stable contract across FPP releases."""
    try:
        req = urllib.request.Request(f"http://localhost/api/settings/{name}")
        with urllib.request.urlopen(req, timeout=5) as r:
            body = r.read().decode().strip()
        try:
            data = json.loads(body)
            if isinstance(data, dict):
                return str(data.get("value", data.get(name, ""))).strip()
            return str(data).strip()
        except json.JSONDecodeError:
            return body
    except Exception as exc:
        _LOGGER.debug("FPP settings API read error for %s: %s", name, exc)
        return ""


def load_fpp_mqtt_settings() -> Dict[str, Any]:
    host = _fpp_setting("MQTTServer")
    if not host:
        return {}
    port_str = _fpp_setting("MQTTPort")
    return {
        "host": host,
        "port": int(port_str) if port_str.isdigit() else 1883,
        "username": _fpp_setting("MQTTUsername"),
        "password": _fpp_setting("MQTTPassword"),
    }


def _now_iso() -> str:
    return datetime.now(timezone.utc).astimezone().isoformat()


class TallyHA:
    def __init__(self, cfg: Dict[str, Any]) -> None:
        self.cfg = cfg
        mqtt_cfg = cfg.get("mqtt", {}) or {}
        self.enabled = bool(mqtt_cfg.get("enabled", False)) and PAHO_AVAILABLE
        self.base = mqtt_cfg.get("base", "tally")
        self.device_name = mqtt_cfg.get("device_name", "Tally Vehicle Counter")
        self.dev_id = "tally_" + socket.gethostname().lower().replace(" ", "_")
        self._client = None
        self._discovered: List[str] = []

        if not self.enabled:
            if mqtt_cfg.get("enabled") and not PAHO_AVAILABLE:
                _LOGGER.warning("MQTT enabled in config but paho-mqtt is not installed")
            return

        settings = load_fpp_mqtt_settings()
        settings.update({k: v for k, v in mqtt_cfg.items() if k in ("host", "port", "username", "password") and v})
        host = settings.get("host")
        if not host:
            _LOGGER.warning("MQTT enabled but no broker host configured (checked FPP settings + tally.json)")
            self.enabled = False
            return

        self._client = mqtt.Client(client_id=self.dev_id)
        if settings.get("username"):
            self._client.username_pw_set(settings["username"], settings.get("password", ""))
        self._client.will_set(f"{self.base}/status", "offline", retain=True)
        try:
            self._client.connect(host, int(settings.get("port", 1883)), keepalive=60)
            self._client.loop_start()
            self._client.publish(f"{self.base}/status", "online", retain=True)
            _LOGGER.info("MQTT connected: %s:%s", host, settings.get("port", 1883))
        except Exception as exc:
            _LOGGER.warning("MQTT connect failed: %s", exc)
            self.enabled = False
            self._client = None

    # ------------------------------------------------------------------
    # Low-level publish helpers
    # ------------------------------------------------------------------

    def _pub(self, topic: str, payload: Any, retain: bool = True) -> None:
        if not self.enabled or self._client is None:
            return
        try:
            body = payload if isinstance(payload, str) else json.dumps(payload)
            self._client.publish(f"{self.base}/{topic}", body, retain=retain)
        except Exception as exc:
            _LOGGER.debug("MQTT publish failed (%s): %s", topic, exc)

    def _discover(self, component: str, obj_id: str, config: Dict[str, Any]) -> None:
        if not self.enabled or self._client is None:
            return
        topic = f"homeassistant/{component}/{self.dev_id}_{obj_id}/config"
        payload = dict(config)
        payload.setdefault("device", {
            "identifiers": [self.dev_id],
            "name": self.device_name,
            "manufacturer": "FocusedOnSound",
            "model": "Tally",
        })
        payload.setdefault("availability_topic", f"{self.base}/status")
        payload.setdefault("unique_id", f"{self.dev_id}_{obj_id}")
        try:
            self._client.publish(topic, json.dumps(payload), retain=True)
            self._discovered.append(topic)
        except Exception as exc:
            _LOGGER.debug("HA discovery publish failed (%s): %s", obj_id, exc)

    # ------------------------------------------------------------------
    # Discovery — per-zone sensors (spec section 9)
    # ------------------------------------------------------------------

    def setup_zone_discovery(self, zone: str, label_a: str, label_b: str) -> None:
        base_state = f"{self.base}/status"
        self._discover("sensor", f"{zone}_cars_today", {
            "name": f"{self.device_name} {zone.title()} Cars Today",
            "state_topic": f"{base_state}/{zone}_cars_today",
            "icon": "mdi:car",
        })
        self._discover("sensor", f"{zone}_cars_total", {
            "name": f"{self.device_name} {zone.title()} Cars Total",
            "state_topic": f"{base_state}/{zone}_cars_total",
            "icon": "mdi:car-multiple",
        })
        self._discover("sensor", f"{zone}_dir_a_today", {
            "name": f"{self.device_name} {zone.title()} {label_a} Today",
            "state_topic": f"{base_state}/{zone}_dir_a_today",
            "icon": "mdi:arrow-right-bold",
        })
        self._discover("sensor", f"{zone}_dir_b_today", {
            "name": f"{self.device_name} {zone.title()} {label_b} Today",
            "state_topic": f"{base_state}/{zone}_dir_b_today",
            "icon": "mdi:arrow-left-bold",
        })
        self._discover("sensor", f"{zone}_parked_today", {
            "name": f"{self.device_name} {zone.title()} Parked Today",
            "state_topic": f"{base_state}/{zone}_parked_today",
            "icon": "mdi:car-brake-parking",
        })
        self._discover("sensor", f"{zone}_parked_total", {
            "name": f"{self.device_name} {zone.title()} Parked Total",
            "state_topic": f"{base_state}/{zone}_parked_total",
            "icon": "mdi:car-brake-parking",
        })
        self._discover("sensor", f"{zone}_parked_conversion_pct", {
            "name": f"{self.device_name} {zone.title()} Parked Conversion %",
            "state_topic": f"{base_state}/{zone}_parked_conversion_pct",
            "unit_of_measurement": "%",
            "icon": "mdi:percent",
        })

    def setup_combined_discovery(self) -> None:
        self._discover("sensor", "combined_cars_today", {
            "name": f"{self.device_name} Combined Cars Today",
            "state_topic": f"{self.base}/status/combined_cars_today",
            "icon": "mdi:car-multiple",
        })

    def setup_crowd_discovery(self) -> None:
        self._discover("sensor", "devices_nearby", {
            "name": f"{self.device_name} Estimated Devices Nearby",
            "state_topic": f"{self.base}/status/devices_nearby",
            "icon": "mdi:bluetooth",
        })

    def setup_environment_discovery(self) -> None:
        self._discover("sensor", "temperature", {
            "name": f"{self.device_name} Temperature",
            "state_topic": f"{self.base}/status/temperature",
            "unit_of_measurement": "°F",
            "device_class": "temperature",
        })
        self._discover("sensor", "humidity", {
            "name": f"{self.device_name} Humidity",
            "state_topic": f"{self.base}/status/humidity",
            "unit_of_measurement": "%",
            "device_class": "humidity",
        })

    def setup_status_discovery(self) -> None:
        self._discover("binary_sensor", "daemon_status", {
            "name": f"{self.device_name} Daemon Status",
            "state_topic": f"{self.base}/status",
            "payload_on": "online",
            "payload_off": "offline",
            "device_class": "connectivity",
        })

    # ------------------------------------------------------------------
    # State setters
    # ------------------------------------------------------------------

    def set_zone_counts(self, zone: str, cars_today: int, cars_total: int,
                         dir_a_today: int, dir_b_today: int,
                         parked_today: int, parked_total: int) -> None:
        self._pub(f"status/{zone}_cars_today", cars_today)
        self._pub(f"status/{zone}_cars_total", cars_total)
        self._pub(f"status/{zone}_dir_a_today", dir_a_today)
        self._pub(f"status/{zone}_dir_b_today", dir_b_today)
        self._pub(f"status/{zone}_parked_today", parked_today)
        self._pub(f"status/{zone}_parked_total", parked_total)
        pct = round((parked_total / cars_total) * 100, 1) if cars_total else 0
        self._pub(f"status/{zone}_parked_conversion_pct", pct)

    def set_combined_cars_today(self, n: int) -> None:
        self._pub("status/combined_cars_today", n)

    def set_devices_nearby(self, n: int) -> None:
        self._pub("status/devices_nearby", n)

    def set_environment(self, temperature_f: Optional[float], humidity_pct: Optional[float]) -> None:
        if temperature_f is not None:
            self._pub("status/temperature", round(temperature_f, 1))
        if humidity_pct is not None:
            self._pub("status/humidity", round(humidity_pct, 1))

    def event(self, kind: str, data: Dict[str, Any]) -> None:
        payload = dict(data)
        payload.setdefault("ts", _now_iso())
        self._pub(f"event/{kind}", payload, retain=False)

    def remove_discovery(self) -> None:
        """Clear every retained discovery config this session published —
        used on plugin uninstall so HA doesn't keep phantom entities."""
        if not self.enabled or self._client is None:
            return
        for topic in self._discovered:
            try:
                self._client.publish(topic, "", retain=True)
            except Exception:
                pass

    def close(self) -> None:
        if self._client is not None:
            try:
                self._client.publish(f"{self.base}/status", "offline", retain=True)
                self._client.loop_stop()
                self._client.disconnect()
            except Exception:
                pass
