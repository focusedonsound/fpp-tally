"""
crowd_wifi.py — Tally's WiFi crowd/device-estimate module (Option 3b).

Passive 802.11 probe-request sniffing in monitor mode — no association, no
content capture, same sampling approach as crowd_ble.py (count unique
source MAC addresses seen in a scan window, hand the raw count to the
daemon for offset/floor).

Defaults to the Pi's onboard adapter (wlan0) rather than assuming a
second USB adapter is always required — plenty of fixed show-prop
installs reach their own network over Ethernet, leaving wlan0 sitting
completely idle (confirmed on real hardware: 192.168.0.51 is Ethernet-
connected with wlan0 down/unused). If the Pi actually uses WiFi for its
own network connection, monitor mode on that same interface would drop
it, so a builder in that situation needs a second USB adapter and must
configure its interface name here instead.

Requirements this module cannot fully self-provision:
  - The configured interface must actually support monitor mode. Known
    risk carried over from the project spec (section 13): confirm this on
    your specific adapter before relying on it — "supports monitor mode on
    Linux" on paper doesn't always mean plug-and-play driver support.
  - Raw 802.11 frame capture needs elevated privileges. Tally's daemon
    normally runs as the unprivileged 'fpp' user (see tally.service), so
    this module will typically fail with a permission error and idle
    rather than silently doing nothing useful -- that failure is logged
    clearly so it's diagnosable, but this build does not attempt to grant
    the daemon raw-socket capabilities (e.g. via setcap) on its own; that's
    a deliberate scope boundary, not an oversight, pending a decision on
    how much privilege escalation this plugin should request.

⚠️ Modern devices randomize their WiFi probe-request MAC address by
default, same caveat as BLE — this is a relative indicator, not a
headcount. Say this prominently in the UI, not just here.
"""
from __future__ import annotations

import time

from .base import SensorModule

try:
    from scapy.all import sniff, Dot11ProbeReq
    SCAPY_AVAILABLE = True
except ImportError:
    SCAPY_AVAILABLE = False
    sniff = None  # type: ignore
    Dot11ProbeReq = None  # type: ignore


def _unique_probe_sources(iface: str, timeout_s: float, sniff_fn=None) -> list:
    """One sniff pass. Returns the sorted list of unique addr2 (source MAC)
    values seen across Dot11ProbeReq frames. sniff_fn is injectable so this
    is testable without a real interface/scapy sniff() call."""
    sniff_fn = sniff_fn or sniff
    seen = set()

    def _handle(pkt):
        if pkt.haslayer(Dot11ProbeReq):
            seen.add(pkt.addr2)

    sniff_fn(iface=iface, prn=_handle, timeout=timeout_s, store=False)
    return sorted(seen)


class CrowdWiFiModule(SensorModule):
    name = "crowd_wifi"

    def run(self) -> None:
        if not SCAPY_AVAILABLE:
            self.log.warning(
                "scapy not importable — WiFi crowd-scan module idle. "
                "Install the optional pip dependency (see fpp_install.sh)."
            )
            while not self._stop.is_set():
                time.sleep(5)
            return

        cs_cfg = self.cfg.get("crowd_scan", {}) or {}
        # Defaults to the onboard adapter -- fine when the Pi reaches its
        # own network over Ethernet (or isn't networked at all) and wlan0
        # would otherwise sit idle, which is the common case for a fixed
        # show-prop install. If the Pi actually uses WiFi for its own
        # network connection, the builder needs a second USB adapter here
        # instead -- monitor mode on the interface the Pi is associated
        # through would drop its own connection. Not auto-detected (see
        # www/index.php's Crowd Scan Config card for the warning copy);
        # network state can change after any check this module could do.
        interface = cs_cfg.get("wifi_interface", "wlan0")
        interval_s = max(10, int(cs_cfg.get("interval_s", 60)))
        scan_timeout_s = min(10.0, interval_s / 2.0)

        self.log.info("WiFi crowd-scan module running: interface=%s interval=%ds",
                       interface, interval_s)

        # Confirmed once, not every loop -- a permission or "no such
        # device" failure is the same error every time, so probing on
        # every cycle would just spam the log with the identical warning.
        probed = False

        while not self._stop.is_set():
            try:
                addresses = _unique_probe_sources(interface, scan_timeout_s)
                self._emit(kind="scan", source="wifi", raw_count=len(addresses))
                # interval_s lets the Diagnostics page judge staleness
                # against this module's own scan cadence -- see the
                # matching comment in crowd_ble.py.
                self._write_live_state("crowd_wifi_live.json", {
                    "addresses": addresses,
                    "count": len(addresses),
                    "interval_s": interval_s,
                })
                self.log.debug("[WiFi] scan: %d unique probe-request sources", len(addresses))
                probed = True
            except PermissionError as exc:
                self.log.error(
                    "WiFi scan failed — permission denied opening a raw socket "
                    "on %s (%s). Tally's daemon runs unprivileged by default; "
                    "this module cannot self-elevate its own privileges.",
                    interface, exc,
                )
                if not probed:
                    # First failure was a permission error -- there's no
                    # reason to expect a retry to succeed differently.
                    # Idle instead of retrying every interval forever.
                    while not self._stop.is_set():
                        time.sleep(5)
                    return
            except Exception as exc:
                self.log.warning("WiFi scan failed on %s: %s", interface, exc)

            slept = 0.0
            while slept < (interval_s - scan_timeout_s) and not self._stop.is_set():
                time.sleep(min(1.0, interval_s - scan_timeout_s - slept))
                slept += 1.0
