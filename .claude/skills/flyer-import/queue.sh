#!/bin/bash
# Imports flyer folders one at a time, in the order they are added to the queue file:
#   echo 67-cebe37e5 >> backend/storage/app/flyer-work/<D>/queue.txt
#   .claude/skills/flyer-import/queue.sh <D> &      (one worker; it waits for new lines, stops on "STOP")
D="$1"
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
WORK="$ROOT/backend/storage/app/flyer-work/$D"
touch "$WORK/queue.txt" "$WORK/queue.done"
while true; do
    next=$(grep -vxF -f "$WORK/queue.done" "$WORK/queue.txt" | head -1)
    [ "$next" = "STOP" ] && exit 0
    if [ -z "$next" ]; then sleep 15; continue; fi
    while ssh -o ServerAliveInterval=30 offeremirait "pgrep -f 'artisan (flyers:import|tinker)' >/dev/null"; do sleep 15; done
    echo "== $(date +%H:%M) $next"
    bash "$ROOT/.claude/skills/flyer-import/push.sh" "$D" "$next" 2>&1 | tail -40
    echo "$next" >> "$WORK/queue.done"
done
