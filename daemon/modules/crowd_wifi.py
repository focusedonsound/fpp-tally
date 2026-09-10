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
    how much privilege escalation this plugin should request. Confirmed
    on real hardware this needs setcap on TWO binaries, not one: the
    daemon's own python3 interpreter (for the raw socket scapy opens
    in-process) AND the separate `iw` binary (for the channel-hopping
    subprocess calls below) -- see README.md for both commands.

⚠️ Modern devices randomize their WiFi probe-request MAC address by
default, same caveat as BLE — this is a relative indicator, not a
headcount. Say this prominently in the UI, not just here.
"""
from __future__ import annotations

import subprocess
import threading
import time

from .base import SensorModule

try:
    from scapy.all import sniff, Dot11ProbeReq
    SCAPY_AVAILABLE = True
except ImportError:
    SCAPY_AVAILABLE = False
    sniff = None  # type: ignore
    Dot11ProbeReq = None  # type: ignore

# Non-overlapping 2.4GHz channels -- covers the common case without
# needing 5GHz support (most monitor-mode-capable USB adapters, including
# the RTL8192CU class confirmed working on real hardware, are 2.4GHz-only).
DEFAULT_HOP_CHANNELS = [1, 6, 11]


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


def _set_channel(iface: str, channel: int, set_channel_fn=None) -> bool:
    run = set_channel_fn or (lambda args: subprocess.run(args, capture_output=True, timeout=2))
    try:
        result = run(["iw", "dev", iface, "set", "channel", str(channel)])
        return getattr(result, "returncode", 1) == 0
    except Exception:
        return False


class _ChannelHopper:
    """Cycles the monitor-mode interface across the configured channels
    while a scan is in progress.

    Confirmed on real hardware this is not optional: switching an adapter
    to monitor mode leaves it parked on a single fixed channel (iw's
    default), so a scan without hopping only ever sees probe requests
    broadcast on that one channel -- WiFi crowd-scan reported 0 unique
    devices in a window where BLE (same daemon, same moment, same real
    devices) reported 14, on 192.168.0.51's RTL8192CU adapter fixed on
    channel 1. Hopping across 1/6/11 gives each channel real dwell time
    within a typical ~10s scan window.

    `iw dev <iface> set channel` needs CAP_NET_ADMIN on the `iw` binary
    itself -- a separate grant from the daemon's own raw-socket
    capability, since `iw` runs as its own subprocess, not inside the
    daemon's own process (see README's setcap instructions for both).
    If that grant is missing, every hop attempt fails identically, so
    this gives up after the first failure and lets the scan continue
    fixed on whatever channel the adapter already has, rather than
    spamming a subprocess call every second for the rest of the scan.
    """

    def __init__(self, iface: str, channels, hop_interval_s: float = 1.0, set_channel_fn=None) -> None:
        self.iface = iface
        self.channels = list(channels) or DEFAULT_HOP_CHANNELS
        self.hop_interval_s = hop_interval_s
        self._set_channel_fn = set_channel_fn
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None
        self.working = True

    def start(self) -> None:
        self.working = True
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        if self._thread is not None:
            self._thread.join(timeout=2)

    def _run(self) -> None:
        i = 0
        while not self._stop.is_set():
            ok = _set_channel(self.iface, self.channels[i % len(self.channels)], self._set_channel_fn)
            if not ok:
                self.working = False
                return
            i += 1
            self._stop.wait(self.hop_interval_s)


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
        hop_channels = cs_cfg.get("wifi_hop_channels", DEFAULT_HOP_CHANNELS)
        hopper = _ChannelHopper(interface, hop_channels) if hop_channels else None

        self.log.info("WiFi crowd-scan module running: interface=%s interval=%ds hop_channels=%s",
                       interface, interval_s, hop_channels or "disabled")

        # Confirmed once, not every loop -- a permission or "no such
        # device" failure is the same error every time, so probing on
        # every cycle would just spam the log with the identical warning.
        probed = False
        hop_warned = False

        while not self._stop.is_set():
            if hopper:
                hopper.start()
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
            finally:
                if hopper:
                    hopper.stop()
                    if not hopper.working and not hop_warned:
                        hop_warned = True
                        self.log.warning(
                            "Channel hopping unavailable on %s (`iw dev %s set channel` "
                            "failed -- needs CAP_NET_ADMIN on the `iw` binary itself, a "
                            "separate grant from the daemon's own raw-socket capability; "
                            "see README.md). Scanning fixed on whatever channel %s is "
                            "already set to -- devices probing on other channels won't "
                            "be seen.",
                            interface, interface, interface,
                        )

            slept = 0.0
            while slept < (interval_s - scan_timeout_s) and not self._stop.is_set():
                time.sleep(min(1.0, interval_s - scan_timeout_s - slept))
                slept += 1.0
