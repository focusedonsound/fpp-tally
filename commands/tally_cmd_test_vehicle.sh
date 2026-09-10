#!/bin/bash
# FPP Command: Tally - Trigger Test Vehicle Event
# Injects a simulated vehicle pass event into the running Tally daemon via
# the command queue. Arg $1 = zone name.

CMD_QUEUE="/home/fpp/media/plugins/fpp-tally/state/tally_trigger.cmd"
mkdir -p "$(dirname "$CMD_QUEUE")" 2>/dev/null || true
LOG_FILE="/home/fpp/media/logs/plugin-fpp-tally.log"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [fpp-cmd] $*" >> "$LOG_FILE"; }

ZONE="${1:-driveway}"
log "TRIGGER: test vehicle event zone=$ZONE"
echo "trigger_vehicle:${ZONE}" > "$CMD_QUEUE"
