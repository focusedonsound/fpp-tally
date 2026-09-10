#!/bin/bash
# FPP Command: Tally - Trigger Test Crowd Scan
# Injects a simulated crowd/device scan result into the running Tally
# daemon via the command queue.

CMD_QUEUE="/home/fpp/media/plugins/fpp-tally/state/tally_trigger.cmd"
mkdir -p "$(dirname "$CMD_QUEUE")" 2>/dev/null || true
LOG_FILE="/home/fpp/media/logs/plugin-fpp-tally.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [fpp-cmd] $*" >> "$LOG_FILE"; }

log "TRIGGER: test crowd scan"
echo "trigger_crowd" > "$CMD_QUEUE"
