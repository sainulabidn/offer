#!/bin/bash
# Imports flyer folders one at a time, in the order they are added to the queue file:
#   echo 67-cebe37e5 >> backend/storage/app/flyer-work/<D>/queue.txt
#   .claude/skills/flyer-import/queue.sh <D> &      (one worker; it waits for new lines, stops on "STOP")
#   "retry:<folder>" re-imports with --retry (items not accepted the first time).
D="$1"
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
WORK="$ROOT/backend/storage/app/flyer-work/$D"
touch "$WORK/queue.txt" "$WORK/queue.done"
# One worker per day folder (Git Bash ps shows no arguments, so a lock directory instead of ps).
mkdir "$WORK/queue.lock" 2>/dev/null || { echo "Another queue worker is running (remove $WORK/queue.lock if not)."; exit 1; }
trap 'rmdir "$WORK/queue.lock"' EXIT
while true; do
    next=$(grep -vxF -f "$WORK/queue.done" "$WORK/queue.txt" | head -1)
    [ "$next" = "STOP" ] && exit 0
    if [ -z "$next" ]; then sleep 15; continue; fi
    while ssh -o ServerAliveInterval=30 offeremirait "pgrep -f 'artisan (flyers:import|tinker)' >/dev/null"; do sleep 15; done
    echo "== $(date +%H:%M) $next"
    case "$next" in retry:*) RETRY=1 bash "$ROOT/.claude/skills/flyer-import/push.sh" "$D" "${next#retry:}" 2>&1 | tail -40 ;; *) bash "$ROOT/.claude/skills/flyer-import/push.sh" "$D" "$next" 2>&1 | tail -40 ;; esac
    echo "$next" >> "$WORK/queue.done"
done
