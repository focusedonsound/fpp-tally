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
    how much privilege escalation this plugin should request. Confirmed on
    real hardware this needs setcap on the daemon's own python3 interpreter
    (for the raw socket scapy opens in-process) -- see README.md.

    The `ip`/`iw` subprocess calls below (channel hopping, setting monitor
    mode) go through `sudo` instead of their own setcap grant -- confirmed
    on real hardware (192.168.0.51, kernel 6.18.50-v7+) that file
    capabilities on `ip`/`iw` do NOT actually take effect for these
    specific operations even when `getcap` reports them present (`ip link
    set <iface> down` still returns "Operation not permitted" as the fpp
    user, reproduced identically via a direct shell and via Python's own
    subprocess call, with no nosuid mount, no systemd sandboxing directives
    on tally.service, and a full capability bounding set on the daemon's
    own process -- root cause not pinned down, not worth the rabbit hole
    when `sudo` reliably works instead). Relies on FPP's existing broad
    passwordless sudo config for the fpp user (confirmed present: `(ALL :
    ALL) NOPASSWD: ALL`), same pattern fpp-hdmi-cec already uses for its
    own privileged commands -- no new sudoers file needed.

_ensure_monitor_mode() below DOES self-provision one thing: re-asserting
monitor mode on the interface itself before every scan window, rather
than requiring it be set once by hand and assuming it stays that way.
Confirmed on real hardware (192.168.0.51) this matters in practice, not
just in theory -- a USB WiFi adapter that drops out and re-enumerates
(power blip on a hub, confirmed via dmesg) resets its wireless type back
to the driver's default (managed), silently losing monitor mode with no
error. sniff() still "succeeds" afterward, it just never captures another
probe request until something re-applies monitor mode.

⚠️ Modern devices randomize their WiFi probe-request MAC address by
default, same caveat as BLE — this is a relative indicator, not a
headcount. Say this prominently in the UI, not just here.

Per-device detail (randomized/fixed address type, OUI vendor when
resolvable, probed SSID(s), RSSI, channel, first/last seen) is captured
for the Diagnostics page only — never persisted to the DB, same
"live-only" reasoning as crowd_ble.py. Probed SSIDs in particular can be
more revealing than a bare MAC (a device's home network name), which is
exactly why this is diagnostics-only and explicitly called out in the
privacy disclosure rather than folded in quietly.

Scanning uses a wider duty cycle than a short pulse (40% of interval_s,
capped - see the CPU-cost comment in run() for why it's 40% and not
closer to 100%) and detections are kept in a rolling window
(crowd_scan.wifi_rolling_window_s, default 300s) rather than each scan
pass replacing the last one wholesale. Confirmed in practice both of
these matter a lot: modern iOS/Android both deliberately throttle how
often an idle, disconnected device sends probe requests at all (an
anti-tracking measure on the phone's own side, working as intended
against exactly this technique), so a real nearby phone can easily go
several minutes between probes even while sitting right there. A short
scan window on a fixed interval, with each pass's result overwriting the
last, made a genuinely-present device look like it kept vanishing and
reappearing at random - the rolling window and wider duty cycle are both
aimed at that specific problem, not at squeezing out marginally more
raw catch rate. Widening the duty cycle all the way to interval_s was
tried and reverted after it contributed to an unplanned reboot on real
hardware - see run()'s comment on scan_timeout_s for the full story.
"""
from __future__ import annotations

import subprocess
import threading
import time

from .base import SensorModule

try:
    from scapy.all import sniff, Dot11ProbeReq, Dot11Elt, RadioTap
    SCAPY_AVAILABLE = True
except ImportError:
    SCAPY_AVAILABLE = False
    sniff = None  # type: ignore
    Dot11ProbeReq = None  # type: ignore
    Dot11Elt = None  # type: ignore
    RadioTap = None  # type: ignore

# Non-overlapping 2.4GHz channels -- covers the common case without
# needing 5GHz support (most monitor-mode-capable USB adapters, including
# the RTL8192CU class confirmed working on real hardware, are 2.4GHz-only).
DEFAULT_HOP_CHANNELS = [1, 6, 11]


def _is_randomized_mac(mac: str) -> bool:
    """True if the MAC's IEEE 802 "locally administered" bit is set (bit
    0x02 of the first octet) - the same bit modern iOS/Android set when
    generating a per-network/per-session random WiFi MAC. This is a
    standards-based fact derived directly from the address bytes, not a
    heuristic, so it's trustworthy even though we can't identify the real
    device behind a randomized address."""
    try:
        first_octet = int(mac.split(":")[0], 16)
    except Exception:
        return False
    return bool(first_octet & 0x02)


def _vendor_lookup(mac: str) -> str | None:
    """Resolve a MAC's OUI to a manufacturer name using scapy's own bundled
    IEEE OUI database (conf.manufdb) rather than hand-maintaining a vendor
    table here - avoids silently mislabeling a device from a guessed or
    stale OUI mapping. Returns None if the OUI isn't in scapy's database or
    the lookup API isn't available on some future scapy version - same
    "degrade to None instead of crashing" approach as crowd_ble.py's
    address_type. Note this identifies the manufacturer registered for
    that MAC block, which for phones is usually the device brand (Apple,
    Samsung) but for laptops/other gear may just be the WiFi chipset
    vendor (Intel, Realtek, Qualcomm) rather than the computer brand."""
    try:
        from scapy.all import conf
        name = conf.manufdb._get_manuf(mac)
    except Exception:
        return None
    if not name or name.upper() == mac.upper():
        return None
    return name


def _extract_rssi(pkt):
    if RadioTap is not None and pkt.haslayer(RadioTap):
        return getattr(pkt[RadioTap], "dBm_AntSignal", None)
    return None


def _extract_probed_ssid(pkt):
    """Returns the SSID this probe request is asking about, or None for a
    wildcard probe (empty/absent SSID element) - devices auto-reconnecting
    to a remembered network send a directed probe naming it by name, which
    is the closest WiFi equivalent to BLE's device "name" field (there's
    no literal device-name field in 802.11 probe requests)."""
    elt = pkt.getlayer(Dot11Elt)
    while elt is not None:
        if getattr(elt, "ID", None) == 0:
            try:
                ssid = elt.info.decode("utf-8", errors="replace")
            except Exception:
                return None
            return ssid or None
        elt = elt.payload.getlayer(Dot11Elt)
    return None


def _scan_probe_requests_detailed(iface: str, timeout_s: float, channel_getter=None, sniff_fn=None) -> dict:
    """One sniff pass. Returns {address: {...}} for every unique addr2
    seen across Dot11ProbeReq frames, same per-window/per-address shape as
    crowd_ble.py's _scan_once_detailed so the Diagnostics page can render
    both the same way. channel_getter, called per-frame, records which
    channel the hopper had the adapter parked on at that moment -
    injectable (like sniff_fn) so this is testable without a real
    ChannelHopper or interface."""
    sniff_fn = sniff_fn or sniff
    devices: dict = {}

    def _handle(pkt):
        if not pkt.haslayer(Dot11ProbeReq):
            return
        addr = pkt.addr2
        if not addr:
            return
        now = time.time()
        entry = devices.get(addr)
        if entry is None:
            randomized = _is_randomized_mac(addr)
            entry = {
                "address": addr,
                "randomized": randomized,
                # OUI lookup on a randomized address would resolve to
                # whatever vendor happened to own that byte range, not the
                # real device - actively misleading rather than merely
                # unavailable, so it's skipped entirely rather than shown.
                "vendor": None if randomized else _vendor_lookup(addr),
                "probed_ssids": [],
                "rssi": None,
                "channel": None,
                "first_seen": now,
            }
            devices[addr] = entry
        entry["last_seen"] = now

        rssi = _extract_rssi(pkt)
        if rssi is not None:
            entry["rssi"] = rssi

        ssid = _extract_probed_ssid(pkt)
        if ssid and ssid not in entry["probed_ssids"]:
            entry["probed_ssids"].append(ssid)

        if channel_getter is not None:
            ch = channel_getter()
            if ch is not None:
                entry["channel"] = ch

    sniff_fn(iface=iface, prn=_handle, timeout=timeout_s, store=False)
    return devices


def _ensure_monitor_mode(iface: str, run_fn=None) -> bool:
    """Idempotently (re-)asserts monitor mode on the given interface before
    each scan window. Cheap and safe to call every window even when
    nothing changed - if `iw dev <iface> info` already reports monitor
    mode this is just the one read-only check and returns immediately.

    Confirmed on real hardware this can't be done through `iw` alone: the
    mac80211/rtl8192cu stack refuses a type change while the interface is
    administratively up, so it needs `ip link set <iface> down`, the type
    change, then `ip link set <iface> up`. Both `ip` and `iw` run via
    `sudo` here, not their own setcap grant - see the module docstring for
    why.
    """
    run = run_fn or (lambda args: subprocess.run(args, capture_output=True, timeout=2))
    try:
        info = run(["sudo", "iw", "dev", iface, "info"])
        out = getattr(info, "stdout", b"") or b""
        if isinstance(out, bytes):
            out = out.decode("utf-8", errors="replace")
        if "type monitor" in out:
            return True
        run(["sudo", "ip", "link", "set", iface, "down"])
        result = run(["sudo", "iw", "dev", iface, "set", "type", "monitor"])
        run(["sudo", "ip", "link", "set", iface, "up"])
        return getattr(result, "returncode", 1) == 0
    except Exception:
        return False


def _merge_into_rolling(rolling: dict, devices: dict) -> None:
    """Folds one scan pass's detections into the persistent rolling-window
    dict, in place. A repeat sighting updates last_seen/rssi/channel and
    unions in any newly-seen probed SSIDs rather than overwriting the
    entry outright - first_seen is preserved from the device's earliest
    appearance, not reset on every pass."""
    for addr, entry in devices.items():
        existing = rolling.get(addr)
        if existing is None:
            rolling[addr] = dict(entry)
            continue
        existing["last_seen"] = entry["last_seen"]
        if entry.get("rssi") is not None:
            existing["rssi"] = entry["rssi"]
        if entry.get("channel") is not None:
            existing["channel"] = entry["channel"]
        for ssid in entry.get("probed_ssids") or []:
            if ssid not in existing["probed_ssids"]:
                existing["probed_ssids"].append(ssid)


def _prune_rolling(rolling: dict, window_s: float, now: float) -> None:
    """Drops anything not seen within the rolling window - confirmed on
    real hardware this matters: without it, a device seen once early in
    the night would sit in "currently nearby" forever, since nothing else
    here ever removes an entry."""
    stale = [addr for addr, entry in rolling.items() if (now - entry["last_seen"]) > window_s]
    for addr in stale:
        del rolling[addr]


def _set_channel(iface: str, channel: int, set_channel_fn=None) -> bool:
    run = set_channel_fn or (lambda args: subprocess.run(args, capture_output=True, timeout=2))
    try:
        result = run(["sudo", "iw", "dev", iface, "set", "channel", str(channel)])
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
    within a scan window.

    hop_interval_s defaults to 3s, not 1s - each hop is a brand new `sudo
    iw` subprocess (fork+exec+sudo+netlink call), and confirmed on real
    hardware (a Pi 3B+, 192.168.0.51) that once the scan window itself got
    widened to nearly the full interval (see crowd_wifi.py's docstring),
    hopping every 1s for that whole duration pegged a CPU core hard enough
    to be the prime suspect in an unplanned watchdog reboot (this board
    runs the 1-minute hardware watchdog systemd enables by default) -
    3s still cycles through all three channels roughly every 9s, plenty
    of dwell time for a scan window measured in tens of seconds, at a
    third of the subprocess-spawn rate.

    `iw dev <iface> set channel` needs CAP_NET_ADMIN on the `iw` binary
    itself -- a separate grant from the daemon's own raw-socket
    capability, since `iw` runs as its own subprocess, not inside the
    daemon's own process (see README's setcap instructions for both).
    If that grant is missing, every hop attempt fails identically, so
    this gives up after the first failure and lets the scan continue
    fixed on whatever channel the adapter already has, rather than
    spamming a subprocess call every few seconds for the rest of the scan.
    """

    def __init__(self, iface: str, channels, hop_interval_s: float = 3.0, set_channel_fn=None) -> None:
        self.iface = iface
        self.channels = list(channels) or DEFAULT_HOP_CHANNELS
        self.hop_interval_s = hop_interval_s
        self._set_channel_fn = set_channel_fn
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None
        self.working = True
        # Read by _scan_probe_requests_detailed's channel_getter to tag
        # each captured frame with the channel the adapter was actually on
        # at that moment - set right after each successful hop, so it's
        # accurate even mid-dwell.
        self.current_channel: int | None = None

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
            ch = self.channels[i % len(self.channels)]
            ok = _set_channel(self.iface, ch, self._set_channel_fn)
            if not ok:
                self.working = False
                return
            self.current_channel = ch
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
        # Widened from a 10s-out-of-60s pulse - confirmed in practice this
        # matters a lot: modern iOS/Android both deliberately throttle how
        # often an idle device sends probe requests (an anti-tracking
        # measure on the phone's own side), so a device that isn't
        # actively background-scanning right at the moment of a brief
        # window can go undetected for many cycles in a row even though
        # it's right there the whole time.
        #
        # NOT widened all the way to interval_s, though, after a real
        # regression: monitor mode delivers EVERY 802.11 frame in range
        # (not just probe requests) to scapy for full Python-level parsing
        # before _handle() ever gets to check haslayer(Dot11ProbeReq) - in
        # a WiFi-dense area that's genuinely expensive, and confirmed on
        # real hardware (192.168.0.51, Pi 3B+) that scanning ~55s out of
        # every 60s pegged a CPU core hard enough to be the prime suspect
        # in an unplanned reboot (this board's systemd watchdog force-
        # reboots after 1 minute of unresponsiveness). Capped at 40% duty
        # cycle instead - still a real improvement over the original 17%,
        # without gambling on device stability to get there. A BPF capture
        # filter (scapy's sniff(filter=...), evaluated in the kernel
        # before a frame ever reaches Python) would cut this cost far more
        # directly and could let the window widen safely again, but that's
        # a next step worth testing carefully rather than shipping
        # untested on a board that's already had two watchdog reboots
        # tonight.
        scan_timeout_s = max(10.0, min(interval_s - 5.0, interval_s * 0.4))
        # How long a sighting stays "currently nearby" after its last
        # probe before aging out of both the Diagnostics table and the
        # count fed to the daemon - without this, each scan pass only
        # reported what it caught in that one pass, so a phone that probed
        # once and then went quiet (common - see above) would show up for
        # a single ~60s window and then silently vanish again, which read
        # as "not working" even though it genuinely was seen.
        rolling_window_s = max(interval_s, float(cs_cfg.get("wifi_rolling_window_s", 300)))
        hop_channels = cs_cfg.get("wifi_hop_channels", DEFAULT_HOP_CHANNELS)
        hop_interval_s = float(cs_cfg.get("wifi_hop_interval_s", 3.0))
        hopper = _ChannelHopper(interface, hop_channels, hop_interval_s=hop_interval_s) if hop_channels else None
        rolling: dict = {}

        self.log.info("WiFi crowd-scan module running: interface=%s interval=%ds hop_channels=%s",
                       interface, interval_s, hop_channels or "disabled")

        # Confirmed once, not every loop -- a permission or "no such
        # device" failure is the same error every time, so probing on
        # every cycle would just spam the log with the identical warning.
        probed = False
        hop_warned = False
        monitor_warned = False

        while not self._stop.is_set():
            if _ensure_monitor_mode(interface):
                monitor_warned = False
            elif not monitor_warned:
                monitor_warned = True
                self.log.warning(
                    "Could not confirm/set monitor mode on %s via `sudo ip`/"
                    "`sudo iw` - check the fpp user has sudo access (see "
                    "README.md). WiFi crowd-scan will keep running but may "
                    "silently see 0 devices if the interface isn't actually "
                    "in monitor mode.",
                    interface,
                )

            if hopper:
                hopper.start()
            try:
                channel_getter = (lambda: hopper.current_channel) if hopper else None
                devices = _scan_probe_requests_detailed(interface, scan_timeout_s, channel_getter=channel_getter)

                _merge_into_rolling(rolling, devices)
                _prune_rolling(rolling, rolling_window_s, time.time())
                addresses = sorted(rolling.keys())

                self._emit(kind="scan", source="wifi", raw_count=len(addresses))
                # interval_s lets the Diagnostics page judge staleness
                # against this module's own scan cadence -- see the
                # matching comment in crowd_ble.py. rolling_window_s is
                # included too so the page can show what "currently
                # nearby" actually means here, since it's now a window,
                # not a single instantaneous pass.
                self._write_live_state("crowd_wifi_live.json", {
                    "devices": [rolling[a] for a in addresses],
                    "count": len(addresses),
                    "interval_s": interval_s,
                    "rolling_window_s": rolling_window_s,
                })
                self.log.debug("[WiFi] scan pass: %d probe-request sources this pass, %d within rolling window",
                                len(devices), len(addresses))
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
                            "Channel hopping unavailable on %s (`sudo iw dev %s set "
                            "channel` failed -- check the fpp user has sudo access; "
                            "see README.md). Scanning fixed on whatever channel %s is "
                            "already set to -- devices probing on other channels won't "
                            "be seen.",
                            interface, interface, interface,
                        )

            slept = 0.0
            while slept < (interval_s - scan_timeout_s) and not self._stop.is_set():
                time.sleep(min(1.0, interval_s - scan_timeout_s - slept))
                slept += 1.0
