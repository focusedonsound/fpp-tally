#!/bin/bash
# callbacks.sh — Tally FPP lifecycle hooks
#
# FPP calls this script with $1 = hook name:
#   pluginStart — FPP has finished booting; start the daemon
#   pluginStop  — FPP is shutting down; stop the daemon
#   getLinks    — FPP is building the Content Setup navigation menu

PLUGIN_DIR="$(dirname "$0")"
DAEMON="${PLUGIN_DIR}/daemon/tally_daemon.py"
PID_FILE="/home/fpp/media/plugins/fpp-tally/state/tally_daemon.pid"
CALIB_SESSION_FILE="/home/fpp/media/plugins/fpp-tally/state/calib_session.json"
LOG_FILE="/home/fpp/media/logs/plugin-fpp-tally.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [callbacks] $*" >> "$LOG_FILE"; }

daemon_start() {
    local waited=0
    while [[ ! -w "/home/fpp/media/logs" ]] && (( waited < 15 )); do
        sleep 1
        (( waited++ )) || true
    done
    mkdir -p "/home/fpp/media/logs" 2>/dev/null || true
    mkdir -p "$(dirname "$PID_FILE")" 2>/dev/null || true

    # Fail-safe: hidden camera calibration mode must default OFF after every
    # boot regardless of prior state (project spec section 6). pluginStart
    # runs on every FPP boot, so this is the actual enforcement point — the
    # install-script reset only covers install/update time.
    rm -f "$CALIB_SESSION_FILE" 2>/dev/null || true

    ENABLED=$(python3 -c "
import json, sys
try:
    cfg = json.load(open('/home/fpp/media/config/tally.json'))
    print('true' if cfg.get('enabled', True) else 'false')
except: print('true')
" 2>/dev/null || echo "true")

    if [[ "$ENABLED" != "true" ]]; then
        log "Plugin disabled in config — not starting daemon"
        return 0
    fi

    # ── Prefer systemd when the service is installed and enabled ─────────────
    _SYSTEMCTL="sudo systemctl"
    if $_SYSTEMCTL is-enabled --quiet tally 2>/dev/null; then
        if $_SYSTEMCTL is-active --quiet tally 2>/dev/null; then
            log "Daemon already running via systemd (skipping nohup start)"
            return 0
        fi
        log "Starting daemon via systemctl..."
        if $_SYSTEMCTL start tally >> "$LOG_FILE" 2>&1; then
            log "Daemon started via systemctl"
            return 0
        fi
        log "WARN: systemctl start failed — falling back to nohup"
    fi

    # ── Fallback: start directly via nohup ───────────────────────────────────
    if [[ -f "$PID_FILE" ]]; then
        PID=$(cat "$PID_FILE" 2>/dev/null || true)
        if [[ -n "$PID" ]] && kill -0 "$PID" 2>/dev/null; then
            if grep -q "tally_daemon" /proc/"$PID"/cmdline 2>/dev/null; then
                log "Daemon already running (PID=$PID)"
                return 0
            fi
        fi
        rm -f "$PID_FILE"
    fi

    if [[ ! -f "$DAEMON" ]]; then
        log "ERROR: daemon not found: $DAEMON"
        return 1
    fi

    log "Starting Tally daemon (nohup)..."
    export PYTHONPATH="${PLUGIN_DIR}/daemon:${PYTHONPATH:-}"
    nohup python3 "$DAEMON" >> "$LOG_FILE" 2>&1 &
    echo "$!" > "$PID_FILE"
    log "Daemon started (PID=$(cat "$PID_FILE"))"
}

daemon_stop() {
    _SYSTEMCTL="sudo systemctl"
    if $_SYSTEMCTL is-enabled --quiet tally 2>/dev/null; then
        log "Stopping daemon via systemctl..."
        $_SYSTEMCTL stop tally >> "$LOG_FILE" 2>&1 || log "WARN: systemctl stop failed"
        return 0
    fi

    if [[ ! -f "$PID_FILE" ]]; then
        log "No PID file found — daemon not running"
        pkill -f "tally_daemon.py" 2>/dev/null || true
        return 0
    fi

    PID=$(cat "$PID_FILE" 2>/dev/null || true)
    if [[ -z "$PID" || ! "$PID" =~ ^[0-9]+$ ]]; then
        log "Invalid PID in file; removing"
        rm -f "$PID_FILE"
        return 0
    fi

    log "Stopping Tally daemon (PID=$PID)..."
    kill -TERM "$PID" 2>/dev/null || true

    waited=0
    while kill -0 "$PID" 2>/dev/null && (( waited < 100 )); do
        sleep 0.1
        (( waited++ )) || true
    done

    if kill -0 "$PID" 2>/dev/null; then
        log "WARN: daemon did not stop cleanly; sending SIGKILL"
        kill -9 "$PID" 2>/dev/null || true
    fi

    rm -f "$PID_FILE"
    log "Daemon stopped"
}

case "${1:-}" in
    pluginStart)
        daemon_start
        ;;
    pluginStop)
        daemon_stop
        ;;
    getLinks)
        cat <<'JSON'
[
  {
    "menu": "content",
    "text": "Tally",
    "url":  "/plugin.php?plugin=fpp-tally&page=www/index.php",
    "icon": "fas fa-fw fa-car"
  }
]
JSON
        ;;
    *)
        exit 0
        ;;
esac
