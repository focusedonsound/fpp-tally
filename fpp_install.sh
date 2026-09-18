#!/bin/bash
# fpp_install.sh — Tally plugin installer
# Called by FPP when the plugin is installed or updated.

PLUGIN_DIR="$(dirname "$0")"

: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common" 2>/dev/null || true
LOGDIR="$(getSetting logDirectory 2>/dev/null)"
LOGDIR="${LOGDIR:-/home/fpp/media/logs}"
LOGFILE="${LOGDIR}/plugin-fpp-tally.log"

log() {
    local msg="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    mkdir -p "$LOGDIR" 2>/dev/null || true
    echo "$msg" >> "$LOGFILE" 2>/dev/null || echo "$msg"
}

log "=== Tally install started (user=$(whoami), uid=$(id -u)) ==="

# ── Create media directories ─────────────────────────────────────
mkdir -p /home/fpp/media/config
mkdir -p /home/fpp/media/plugins/fpp-tally/state
# This installer runs as root, so a bare mkdir leaves the directory
# root:root -- but the web UI (PHP, running as the 'fpp' user) needs to
# write into it too (trigger.php's command queue, the hidden calibration
# route's session file), regardless of which user ends up starting the
# daemon itself (root at early boot vs. fpp via callbacks.sh, depending on
# how FPP invokes plugin lifecycle hooks on a given install). chown here
# once, up front, rather than requiring every script that touches this
# directory to defensively fix ownership on every write.
chown -R fpp:fpp /home/fpp/media/plugins/fpp-tally/state 2>/dev/null \
    || log "WARN: could not chown state dir to fpp:fpp (web UI writes there may fail until this is fixed manually)"

# pluginInfo.json's dependencies.packages block already declares the apt
# packages this plugin needs, so FPP 10+ installs them before this script
# runs (FPP_DEPS_RESOLVED=1 is exported in that case). Only install them by
# hand here as a fallback for FPP 9, which silently ignores the dependencies
# block.
if [ -z "${FPP_DEPS_RESOLVED:-}" ]; then
    log "Installing system packages via apt-get..."
    if apt-get install -y --no-install-recommends \
        sqlite3 \
        python3-pip \
        python3-smbus \
        python3-serial \
        python3-paho-mqtt \
        >> "$LOGFILE" 2>&1; then
        log "apt-get packages installed OK"
    else
        log "WARN: apt-get failed or partial — one or more Python deps may be missing"
    fi
else
    log "Dependencies already resolved by FPP (FPP_DEPS_RESOLVED=1); skipping manual apt-get."
fi

# ── Optional: BLE / MLX90640 / BME280 python libraries ────────────
# Not declared in pluginInfo.json's dependencies (only needed by builders
# who enable those specific hardware-selection checkboxes), and some aren't
# on apt at all -- pip is the right tool here per the plugin guidelines'
# ad-hoc install rule. Installs into FPP's system Python, best-effort: a
# failure here disables only that module, never the whole plugin.
if python3 -m pip --version >/dev/null 2>&1; then
    log "Installing optional sensor-module Python libraries..."
    python3 -m pip install --quiet --break-system-packages \
        bleak scapy adafruit-circuitpython-mlx90640 adafruit-circuitpython-bme280 \
        adafruit-circuitpython-dht \
        >> "$LOGFILE" 2>&1 \
        || log "WARN: one or more optional libraries failed to install (non-fatal — that module stays disabled until resolved)"
fi

# ── Optional: camera-assisted classification (python3-opencv + model) ──
# Not in pluginInfo.json's mandatory dependencies block -- python3-opencv
# pulls in ~75MB of libraries (libvtk, libopenmpi, etc.), and the camera
# module defaults to disabled, so forcing every builder to install it
# unconditionally (even those with no camera hardware at all) would be a
# poor trade for a feature most installs won't use. Best-effort and
# non-fatal either way: if this fails, the camera module logs an error and
# idles, same as any other optional module with missing hardware/libraries.
log "Installing optional camera-classification support..."
if apt-get install -y --no-install-recommends python3-opencv ffmpeg \
    >> "$LOGFILE" 2>&1; then
    log "python3-opencv/ffmpeg installed OK"
else
    log "WARN: python3-opencv/ffmpeg install failed (non-fatal — camera module stays disabled until resolved)"
fi

MODEL_DIR="${PLUGIN_DIR}/daemon/models/ssd_mobilenet_v1_coco"
if [[ ! -f "${MODEL_DIR}/frozen_inference_graph.pb" ]]; then
    log "Downloading camera classification model (TF MobileNet-SSD v1 COCO)..."
    mkdir -p "$MODEL_DIR"
    TMP_TAR="$(mktemp)"
    if curl -fsSL -o "$TMP_TAR" \
        "http://download.tensorflow.org/models/object_detection/ssd_mobilenet_v1_coco_2017_11_17.tar.gz" \
        && tar -xzf "$TMP_TAR" -O ssd_mobilenet_v1_coco_2017_11_17/frozen_inference_graph.pb \
            > "${MODEL_DIR}/frozen_inference_graph.pb" \
        && curl -fsSL -o "${MODEL_DIR}/graph.pbtxt" \
            "https://raw.githubusercontent.com/opencv/opencv_extra/master/testdata/dnn/ssd_mobilenet_v1_coco_2017_11_17.pbtxt" \
        && curl -fsSL -o "${MODEL_DIR}/labels.txt" \
            "https://raw.githubusercontent.com/opencv/opencv/master/samples/data/dnn/object_detection_classes_coco.txt"
    then
        log "Camera classification model downloaded OK"
    else
        log "WARN: camera classification model download failed (non-fatal — camera module stays disabled until resolved; re-run this installer once network access is available to retry)"
        rm -f "${MODEL_DIR}/frozen_inference_graph.pb" "${MODEL_DIR}/graph.pbtxt" "${MODEL_DIR}/labels.txt"
    fi
    rm -f "$TMP_TAR"
else
    log "Camera classification model already present — skipping download"
fi

# ── Make scripts executable ──────────────────────────────────────
log "Setting script permissions..."
chmod +x "${PLUGIN_DIR}/daemon/"*.py 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/daemon/modules/"*.py 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/commands/"*.sh 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/callbacks.sh" 2>/dev/null || true

# ── Write default config if none exists ─────────────────────────
# Found on real hardware (not caught by container-based testing, which had
# no unprivileged web-server user to expose it): this installer runs as
# root, so a bare `cp` here leaves the file root:root -- but the Setup
# page's save.php runs as the unprivileged 'fpp' user (PHP-FPM's pool
# user), which then can't write its own settings on a fresh install. chown
# it the same way the state directory already is (see above) rather than
# leaving every future Setup-page Save silently fail until someone
# happens to fix ownership by hand over SSH.
CONFIG="/home/fpp/media/config/tally.json"
if [[ ! -f "$CONFIG" ]]; then
    log "Writing default config to $CONFIG"
    cp "${PLUGIN_DIR}/config/tally.json.example" "$CONFIG" 2>/dev/null \
        || log "WARN: could not copy default config"
fi
chown fpp:fpp "$CONFIG" 2>/dev/null \
    || log "WARN: could not chown $CONFIG to fpp:fpp (Setup page saves may fail until this is fixed manually)"

# ── Hidden camera calibration mode: fail-safe reset ──────────────
# Section 6 of the project spec: calibration mode must default OFF after
# every boot or service restart, regardless of prior state. The install
# script is not itself a boot hook, but this covers the "just installed /
# just updated" case; callbacks.sh's pluginStart does the same reset on
# every actual boot.
rm -f "/home/fpp/media/plugins/fpp-tally/state/calib_session.json" 2>/dev/null || true

# ── Systemd service ───────────────────────────────────────────────
SERVICE_SRC="${PLUGIN_DIR}/tally.service"
SERVICE_DST="/etc/systemd/system/tally.service"

if [[ -f "$SERVICE_SRC" ]]; then
    log "Installing systemd service..."
    cp "$SERVICE_SRC" "$SERVICE_DST" && log "Service file copied OK" || log "WARN: could not copy service file"
    systemctl daemon-reload >> "$LOGFILE" 2>&1 && log "systemctl daemon-reload OK" || log "WARN: daemon-reload failed"
    systemctl enable tally >> "$LOGFILE" 2>&1 && log "tally enabled OK" || log "WARN: enable failed"
    if systemctl restart tally >> "$LOGFILE" 2>&1; then
        log "tally service started OK"
    else
        log "WARN: could not start tally service (non-fatal — daemon can be started manually)"
    fi
else
    log "WARN: tally.service not found in plugin dir — skipping systemd install"
fi

# ── Sudoers rule for fpp user ────────────────────────────────────
# FPP runs as root but its web UI (PHP) and plugin callbacks run as the
# 'fpp' user. Without this rule, 'systemctl start/stop tally' from
# callbacks.sh or the Setup page's daemon controls returns "Interactive
# authentication required" and silently falls back to a bare nohup launch
# (no auto-restart on crash). This rule grants the fpp user passwordless
# control of this one service only.
SUDOERS_FILE="/etc/sudoers.d/tally"
cat > "${SUDOERS_FILE}.tmp" << 'SUDOEOF'
# Tally — allow fpp user to control its own daemon service without a
# password prompt. Scope is intentionally limited to this service.
fpp ALL=(ALL) NOPASSWD: \
    /bin/systemctl start tally, \
    /bin/systemctl stop tally, \
    /bin/systemctl restart tally, \
    /bin/systemctl is-active tally, \
    /bin/systemctl is-enabled tally, \
    /usr/bin/systemctl start tally, \
    /usr/bin/systemctl stop tally, \
    /usr/bin/systemctl restart tally, \
    /usr/bin/systemctl is-active tally, \
    /usr/bin/systemctl is-enabled tally
SUDOEOF
chmod 0440 "${SUDOERS_FILE}.tmp"
if visudo -cf "${SUDOERS_FILE}.tmp" >> "$LOGFILE" 2>&1; then
    mv "${SUDOERS_FILE}.tmp" "$SUDOERS_FILE"
    log "Sudoers rule installed: $SUDOERS_FILE"
else
    rm -f "${SUDOERS_FILE}.tmp"
    log "WARN: sudoers rule validation failed — rule not installed (daemon control may require manual start)"
fi

# ── Fix log file ownership ────────────────────────────────────────
# Found on real hardware: this script's own log() calls create
# $LOGFILE as root (this installer runs as root), and the daemon then
# runs as the unprivileged 'fpp' user (see tally.service). Under
# systemd that's masked -- systemd itself opens the StandardOutput/
# StandardError append-redirect as root before dropping to User=fpp,
# so log lines appear to land fine even though Python's own
# logging.FileHandler(LOG_FILE) is silently failing and falling back
# to stderr underneath. That fallback isn't there at all under the
# nohup path callbacks.sh uses when systemd isn't managing the
# service, so this has to be fixed at the source rather than relied
# on to keep accidentally working.
chown fpp:fpp "$LOGFILE" 2>/dev/null || true

log "=== Tally install complete ==="

# cowsay-style speech bubble that word-wraps to fit whatever text it's
# given, rather than a fixed-width box hand-tuned per joke. Never mix a
# literal backslash into a printf FORMAT string here -- pass it as %s data
# instead (see the bs='\' variable below); a backslash sitting next to \n
# in a format string is ambiguous across shells and silently prints "\n"
# literally instead of a newline on at least one of them.
render_speech_bubble() {
    local text="$1" maxwidth=44 bs='\'
    local -a lines=()
    local line=""
    for word in $text; do
        if [ -z "$line" ]; then
            line="$word"
        elif [ $((${#line} + 1 + ${#word})) -le "$maxwidth" ]; then
            line="$line $word"
        else
            lines+=("$line")
            line="$word"
        fi
    done
    [ -n "$line" ] && lines+=("$line")

    local width=0 l
    for l in "${lines[@]}"; do
        [ ${#l} -gt "$width" ] && width=${#l}
    done

    local top bot padded n=${#lines[@]}
    top=$(printf '%*s' "$((width + 2))" '' | tr ' ' '_')
    bot=$(printf '%*s' "$((width + 2))" '' | tr ' ' '-')
    printf '%s\n' " ${top}"
    if [ "$n" -eq 1 ]; then
        padded=$(printf '%-*s' "$width" "${lines[0]}")
        printf '%s\n' "< ${padded} >"
    else
        local i
        for i in "${!lines[@]}"; do
            padded=$(printf '%-*s' "$width" "${lines[$i]}")
            if [ "$i" -eq 0 ]; then
                printf '%s\n' "/ ${padded} ${bs}"
            elif [ "$i" -eq $((n - 1)) ]; then
                printf '%s\n' "${bs} ${padded} /"
            else
                printf '%s\n' "| ${padded} |"
            fi
        done
    fi
    printf '%s\n' " ${bot}"
}

# A little something for whoever's actually reading the install log. Only
# ever recommends a sibling plugin that isn't already sitting right next to
# this one, so it never suggests something you've clearly already got. A
# 1-in-7 roll swaps the everyday joke pool for a separate "rare drop" pool
# with its own art framing, instead of just re-skinning the same box.
_show_easter_egg_render() {
    local plugin_dir_abs
    plugin_dir_abs="$(cd "$PLUGIN_DIR" && pwd)"
    local plugins_root
    plugins_root="$(dirname "$plugin_dir_abs")"

    local siblings=(
        "fpp-hdmi-cec|controls your TV/monitor power and input over HDMI-CEC"
        "fpp-EncoreRadio|keeps the radio-station vibe going after the show ends"
        "fpp-sled-mailbox|a smart Letters-to-Santa mailbox with visitor detection"
        "fpp-AnnouncementAssistant|one-tap announcements ducked over your show audio"
    )
    local jokes=(
        "Why did the car get a ticket from Tally? Speeding through a counting zone."
        "I asked my radar gun out. It said I wasn't fast enough for it."
        "My thermal camera never gets cold feet. Or cold anything, really."
        "Why did the truck brag about its Tally stats? It's always counted among the greats."
    )
    local rare_jokes=(
        "Legend says one traffic count in seven includes a car driven by Bigfoot."
        "Rare stat unlocked: this radar gun has never once been wrong. Ever."
        "You've found the one Tally install with a genuinely law-abiding neighborhood."
    )

    local candidates=()
    local entry repo blurb
    for entry in "${siblings[@]}"; do
        repo="${entry%%|*}"
        [ -d "${plugins_root}/${repo}" ] || candidates+=("$entry")
    done

    local wordmark mascot
    wordmark=$(cat <<'WORDMARK'
#####   ###   #      #      #   #
  #    #   #  #      #      #   #
  #    #####  #      #       # #
  #    #   #  #      #        #
  #    #   #  #####  #####    #
WORDMARK
)
    mascot=$(cat <<'MASCOT'
          ______
       __/  ..  \______
      |  _      _      |
      '-(_)----(_)------'
MASCOT
)

    local is_rare=0
    [ $((RANDOM % 7)) -eq 0 ] && is_rare=1

    echo
    echo "$wordmark"
    echo
    if [ "$is_rare" -eq 1 ]; then
        echo "  *** RARE DROP (1-in-7) — fpp-tally ***"
        echo
        render_speech_bubble "${rare_jokes[$((RANDOM % ${#rare_jokes[@]}))]}"
    else
        echo "  🏆 ACHIEVEMENT UNLOCKED — fpp-tally installed & ready to roll"
        echo
        render_speech_bubble "${jokes[$((RANDOM % ${#jokes[@]}))]}"
    fi
    echo "$mascot"
    echo

    if [ "$is_rare" -eq 0 ]; then
        local stars=$((3 + RANDOM % 3)) s rating=""
        for ((s = 0; s < 5; s++)); do
            if [ "$s" -lt "$stars" ]; then rating="${rating}★"; else rating="${rating}☆"; fi
        done
        echo "  dad-joke rating: ${rating}  (${stars}/5 groans)"
        echo
    fi

    echo "  ----------------------------------------"
    if [ ${#candidates[@]} -gt 0 ]; then
        entry="${candidates[$((RANDOM % ${#candidates[@]}))]}"
        repo="${entry%%|*}"
        blurb="${entry#*|}"
        echo "  🎁 NEXT UP: ${repo}"
        echo "     ${blurb}"
        echo "     https://github.com/focusedonsound/${repo}"
    else
        echo "  🎉 FULL COLLECTION UNLOCKED — every FocusedOnSound plugin, right here."
    fi
    echo "  ----------------------------------------"
    echo
}

# pluginsProgressPopupText (the "Upgrade Plugin" dialog) is a <div>, not a
# real <textarea>/<pre> -- FPP core's StreamURL() inserts our output via
# innerHTML with only \n -> <br> conversion (see www/js/fpp.js), so normal
# HTML whitespace collapsing squashes every run of spaces down to one,
# wrecking any column-aligned ASCII art. A non-breaking space (U+00A0) is
# never collapsed, so render everything normally and swap plain spaces for
# nbsp right before printing, rather than trying to build every line out of
# nbsp by hand.
show_easter_egg() {
    _show_easter_egg_render | sed 's/ /\xc2\xa0/g'
}
show_easter_egg

exit 0
