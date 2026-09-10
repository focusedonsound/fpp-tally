#!/bin/bash
# fpp_uninstall.sh — Tally plugin uninstaller
# Called by FPP when the plugin is removed. Mirrors fpp_install.sh's
# systemd service setup in reverse. Safe to run twice.

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

log "=== Tally uninstall started ==="

if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet tally 2>/dev/null; then
        systemctl stop tally || true
    fi
    systemctl disable tally 2>/dev/null || true

    SERVICE_DST="/etc/systemd/system/tally.service"
    if [[ -f "$SERVICE_DST" ]]; then
        rm -f "$SERVICE_DST"
        systemctl daemon-reload
        log "Removed tally.service"
    fi
fi

rm -f "/etc/sudoers.d/tally" 2>/dev/null || true

: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common" 2>/dev/null || true
setSetting restartFlag 1 2>/dev/null || true

log "=== Tally uninstall complete. Config and database left in place. ==="
exit 0
