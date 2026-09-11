"""
tally_db.py — SQLite persistence layer for Tally.

Independent database (tally.db), independent schema — no dependency on
fpp-sled-mailbox's sled.db or any table it defines. Schema matches the
Tally project spec section 7 exactly:

  events         — discrete vehicle detection events, any zone/module
  device_scans   — periodic crowd/device sampling snapshots
  environment    — BME280 temperature/humidity readings
"""
from __future__ import annotations

import os
import sqlite3
import time
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

_STATE_DIR = "/home/fpp/media/plugins/fpp-tally/state"
os.makedirs(_STATE_DIR, exist_ok=True)
DB_PATH = os.path.join(_STATE_DIR, "tally.db")

_DDL = """
CREATE TABLE IF NOT EXISTS events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp       DATETIME NOT NULL,
    zone            TEXT NOT NULL,
    sensor_source   TEXT NOT NULL,
    event_type      TEXT NOT NULL,
    direction       TEXT,
    dwell_duration_s INTEGER,
    speed_estimate  REAL
);
CREATE INDEX IF NOT EXISTS idx_events_ts   ON events(timestamp);
CREATE INDEX IF NOT EXISTS idx_events_zone ON events(zone);

CREATE TABLE IF NOT EXISTS device_scans (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp       DATETIME NOT NULL,
    source          TEXT NOT NULL,
    raw_count       INTEGER NOT NULL,
    offset_applied  INTEGER NOT NULL DEFAULT 0,
    adjusted_count  INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_device_scans_ts ON device_scans(timestamp);

CREATE TABLE IF NOT EXISTS environment (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp       DATETIME NOT NULL,
    temperature_f   REAL,
    humidity_pct    REAL
);
CREATE INDEX IF NOT EXISTS idx_environment_ts ON environment(timestamp);

CREATE TABLE IF NOT EXISTS pass_features (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp         DATETIME NOT NULL,
    zone              TEXT NOT NULL,
    direction         TEXT,
    speed_estimate    REAL,
    peak_energy       INTEGER,
    gates_lit         INTEGER,
    camera_label      TEXT,
    camera_confidence REAL
);
CREATE INDEX IF NOT EXISTS idx_pass_features_ts    ON pass_features(timestamp);
CREATE INDEX IF NOT EXISTS idx_pass_features_label  ON pass_features(camera_label);
"""

# camera_label -> coarse vehicle/not_vehicle bucket, for label_energy_stats().
# COCO class names as returned by the camera module's classifier.
VEHICLE_LABELS = {"car", "truck", "bus", "motorcycle"}
NON_VEHICLE_LABELS = {"person", "dog", "cat", "bicycle"}

# event_type values
EVENT_PASS   = "pass"
EVENT_PARKED = "parked"

# sensor_source values
SOURCE_LD2410  = "ld2410b"
SOURCE_THERMAL = "thermal"

# device_scans source values
SCAN_BLE      = "ble"
SCAN_WIFI     = "wifi"
SCAN_COMBINED = "combined"


def _now_iso() -> str:
    return datetime.now(timezone.utc).astimezone().isoformat()


def _today_str() -> str:
    return datetime.now().strftime("%Y-%m-%d")


class TallyDB:
    def __init__(self, path: str = DB_PATH) -> None:
        self.path = path
        self._con: Optional[sqlite3.Connection] = None
        self._open()

    def _open(self) -> None:
        self._con = sqlite3.connect(self.path, check_same_thread=False)
        self._con.row_factory = sqlite3.Row
        self._con.execute("PRAGMA journal_mode=WAL")
        self._con.executescript(_DDL)
        self._con.commit()

    def close(self) -> None:
        if self._con:
            self._con.close()
            self._con = None

    # ------------------------------------------------------------------
    # events
    # ------------------------------------------------------------------

    def log_event(
        self,
        zone: str,
        sensor_source: str,
        event_type: str,
        direction: Optional[str] = None,
        dwell_duration_s: Optional[int] = None,
        speed_estimate: Optional[float] = None,
    ) -> int:
        assert self._con
        cur = self._con.execute(
            "INSERT INTO events (timestamp, zone, sensor_source, event_type, "
            "direction, dwell_duration_s, speed_estimate) VALUES (?,?,?,?,?,?,?)",
            (_now_iso(), zone, sensor_source, event_type, direction,
             dwell_duration_s, speed_estimate),
        )
        self._con.commit()
        return int(cur.lastrowid)

    def count_events_today(
        self, zone: Optional[str] = None, event_type: Optional[str] = None,
        direction: Optional[str] = None,
    ) -> int:
        today = _today_str()
        sql = "SELECT COUNT(*) FROM events WHERE substr(timestamp,1,10)=?"
        params: List[Any] = [today]
        if zone:
            sql += " AND zone=?"
            params.append(zone)
        if event_type:
            sql += " AND event_type=?"
            params.append(event_type)
        if direction:
            sql += " AND direction=?"
            params.append(direction)
        row = self._con.execute(sql, params).fetchone()  # type: ignore[union-attr]
        return int(row[0]) if row else 0

    def count_events_total(
        self, zone: Optional[str] = None, event_type: Optional[str] = None,
        direction: Optional[str] = None,
    ) -> int:
        sql = "SELECT COUNT(*) FROM events WHERE 1=1"
        params: List[Any] = []
        if zone:
            sql += " AND zone=?"
            params.append(zone)
        if event_type:
            sql += " AND event_type=?"
            params.append(event_type)
        if direction:
            sql += " AND direction=?"
            params.append(direction)
        row = self._con.execute(sql, params).fetchone()  # type: ignore[union-attr]
        return int(row[0]) if row else 0

    def recent_events(self, limit: int = 20) -> List[Dict[str, Any]]:
        cur = self._con.execute(  # type: ignore[union-attr]
            "SELECT id, timestamp, zone, sensor_source, event_type, direction, "
            "dwell_duration_s, speed_estimate FROM events ORDER BY id DESC LIMIT ?",
            (limit,),
        )
        return [dict(r) for r in cur.fetchall()]

    def hourly_distribution(self, days: int = 30, zone: Optional[str] = None) -> Dict[str, int]:
        """Vehicle pass counts bucketed by hour-of-day (0-23) over the last N days.

        Events are stored as ISO8601 with an explicit local UTC offset
        (see _now_iso()); SQLite's date functions normalize that to UTC
        internally before formatting, so a bare strftime('%H', ...) here
        would silently bucket by UTC hour instead of the Pi's actual local
        hour -- exactly backwards for a "what time of day do vehicles pass"
        report. The 'localtime' modifier converts back to this machine's
        local timezone before extracting the hour.
        """
        sql = (
            "SELECT strftime('%H', timestamp, 'localtime') AS hr, COUNT(*) AS n FROM events "
            "WHERE event_type='pass' AND timestamp >= datetime('now', ?)"
        )
        params: List[Any] = [f"-{int(days)} days"]
        if zone:
            sql += " AND zone=?"
            params.append(zone)
        sql += " GROUP BY hr"
        cur = self._con.execute(sql, params)  # type: ignore[union-attr]
        out = {f"{h:02d}": 0 for h in range(24)}
        for row in cur.fetchall():
            if row["hr"] is not None:
                out[row["hr"]] = int(row["n"])
        return out

    def daily_counts(self, days: int = 30, zone: Optional[str] = None) -> Dict[str, Dict[str, int]]:
        """Per-day pass/parked counts over the last N days, keyed YYYY-MM-DD."""
        sql = (
            "SELECT substr(timestamp,1,10) AS d, event_type, COUNT(*) AS n FROM events "
            "WHERE timestamp >= datetime('now', ?)"
        )
        params: List[Any] = [f"-{int(days)} days"]
        if zone:
            sql += " AND zone=?"
            params.append(zone)
        sql += " GROUP BY d, event_type"
        cur = self._con.execute(sql, params)  # type: ignore[union-attr]
        out: Dict[str, Dict[str, int]] = {}
        for row in cur.fetchall():
            out.setdefault(row["d"], {"pass": 0, "parked": 0})[row["event_type"]] = int(row["n"])
        return out

    # ------------------------------------------------------------------
    # device_scans
    # ------------------------------------------------------------------

    def log_scan(self, source: str, raw_count: int, offset_applied: int) -> int:
        adjusted = max(0, raw_count - offset_applied)
        assert self._con
        cur = self._con.execute(
            "INSERT INTO device_scans (timestamp, source, raw_count, offset_applied, "
            "adjusted_count) VALUES (?,?,?,?,?)",
            (_now_iso(), source, raw_count, offset_applied, adjusted),
        )
        self._con.commit()
        return int(cur.lastrowid)

    def latest_scan(self, source: Optional[str] = None) -> Optional[Dict[str, Any]]:
        sql = "SELECT * FROM device_scans"
        params: List[Any] = []
        if source:
            sql += " WHERE source=?"
            params.append(source)
        sql += " ORDER BY id DESC LIMIT 1"
        row = self._con.execute(sql, params).fetchone()  # type: ignore[union-attr]
        return dict(row) if row else None

    def scan_history(self, days: int = 7, source: Optional[str] = None) -> List[Dict[str, Any]]:
        sql = "SELECT * FROM device_scans WHERE timestamp >= datetime('now', ?)"
        params: List[Any] = [f"-{int(days)} days"]
        if source:
            sql += " AND source=?"
            params.append(source)
        sql += " ORDER BY id ASC"
        cur = self._con.execute(sql, params)  # type: ignore[union-attr]
        return [dict(r) for r in cur.fetchall()]

    # ------------------------------------------------------------------
    # environment
    # ------------------------------------------------------------------

    def log_environment(self, temperature_f: Optional[float], humidity_pct: Optional[float]) -> int:
        assert self._con
        cur = self._con.execute(
            "INSERT INTO environment (timestamp, temperature_f, humidity_pct) VALUES (?,?,?)",
            (_now_iso(), temperature_f, humidity_pct),
        )
        self._con.commit()
        return int(cur.lastrowid)

    def latest_environment(self) -> Optional[Dict[str, Any]]:
        row = self._con.execute(  # type: ignore[union-attr]
            "SELECT * FROM environment ORDER BY id DESC LIMIT 1"
        ).fetchone()
        return dict(row) if row else None

    def environment_history(self, days: int = 7) -> List[Dict[str, Any]]:
        cur = self._con.execute(  # type: ignore[union-attr]
            "SELECT * FROM environment WHERE timestamp >= datetime('now', ?) ORDER BY id ASC",
            (f"-{int(days)} days",),
        )
        return [dict(r) for r in cur.fetchall()]

    # ------------------------------------------------------------------
    # Diagnostics / reset
    # ------------------------------------------------------------------

    # ------------------------------------------------------------------
    # pass_features (camera-assisted tuning)
    # ------------------------------------------------------------------

    def log_pass_features(
        self,
        zone: str,
        direction: Optional[str],
        speed_estimate: Optional[float],
        peak_energy: Optional[int],
        gates_lit: Optional[int],
        camera_label: Optional[str],
        camera_confidence: Optional[float],
    ) -> int:
        assert self._con
        cur = self._con.execute(
            "INSERT INTO pass_features (timestamp, zone, direction, speed_estimate, "
            "peak_energy, gates_lit, camera_label, camera_confidence) VALUES (?,?,?,?,?,?,?,?)",
            (_now_iso(), zone, direction, speed_estimate, peak_energy, gates_lit,
             camera_label, camera_confidence),
        )
        self._con.commit()
        return int(cur.lastrowid)

    def label_energy_stats(self) -> Dict[str, Any]:
        """Sample counts and peak_energy min/max/avg for the vehicle vs.
        not_vehicle buckets, plus a suggested min_energy threshold (lowest
        confirmed-vehicle peak_energy, so it's inclusive of every vehicle
        seen so far) -- the input the Diagnostics page's camera-tuning
        panel needs. Rows with an unrecognized/missing label or peak_energy
        are excluded from both buckets rather than guessed at."""
        assert self._con
        placeholders_v = ",".join("?" * len(VEHICLE_LABELS))
        placeholders_nv = ",".join("?" * len(NON_VEHICLE_LABELS))
        row_v = self._con.execute(
            f"SELECT COUNT(*) n, MIN(peak_energy) lo, MAX(peak_energy) hi, AVG(peak_energy) avg "
            f"FROM pass_features WHERE camera_label IN ({placeholders_v}) AND peak_energy IS NOT NULL",
            tuple(VEHICLE_LABELS),
        ).fetchone()
        row_nv = self._con.execute(
            f"SELECT COUNT(*) n, MIN(peak_energy) lo, MAX(peak_energy) hi, AVG(peak_energy) avg "
            f"FROM pass_features WHERE camera_label IN ({placeholders_nv}) AND peak_energy IS NOT NULL",
            tuple(NON_VEHICLE_LABELS),
        ).fetchone()

        def _bucket(row):
            return {
                "count": int(row["n"] or 0),
                "min_energy": row["lo"],
                "max_energy": row["hi"],
                "avg_energy": round(row["avg"], 1) if row["avg"] is not None else None,
            }

        vehicle = _bucket(row_v)
        not_vehicle = _bucket(row_nv)

        # Suggested threshold: the lowest peak_energy seen on a confirmed
        # vehicle -- everything at or above it kept every vehicle observed
        # so far. Only offered once there's enough of both classes to be
        # meaningful; never computed from one bucket alone.
        suggested_min_energy = None
        if vehicle["count"] >= 15 and not_vehicle["count"] >= 15 and vehicle["min_energy"] is not None:
            suggested_min_energy = vehicle["min_energy"]

        return {
            "vehicle": vehicle,
            "not_vehicle": not_vehicle,
            "suggested_min_energy": suggested_min_energy,
        }

    def reset_today(self) -> int:
        """Delete today's events (test data cleanup). Returns rows removed."""
        today = _today_str()
        assert self._con
        cur = self._con.execute(
            "DELETE FROM events WHERE substr(timestamp,1,10)=?", (today,)
        )
        self._con.commit()
        return cur.rowcount
