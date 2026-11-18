#!/bin/bash
# Upload flyer folders (files + manifest.json) to live and import them.
#   .claude/skills/flyer-import/push.sh 2026-11-17 14-4e3b48d6 68-6fcb69c7 ...
# The import runs detached on the server (nohup, log in the flyer's folder) so a dropped SSH connection
# doesn't kill it; this script polls until it ends and prints the log.
set -euo pipefail
D="$1"; shift
WORK="$(cd "$(dirname "$0")/../../.." && pwd)/backend/storage/app/flyer-work/$D"
PHP=/opt/cpanel/ea-php84/root/usr/bin/php
SSH="ssh -o ServerAliveInterval=30 -o ServerAliveCountMax=10 offeremirait"
for f in "$@"; do
    f="${f%/}"
    $SSH "mkdir -p ~/flyer-imports/$D/$f"
    files=$(tr -d '\n' < "$WORK/$f/manifest.json" | grep -o '"files": *\[[^]]*\]' | grep -o '"[^"]*\.\(pdf\|jpe\?g\|png\|webp\)"' | tr -d '"')
    (cd "$WORK/$f" && scp -q -o ServerAliveInterval=30 manifest.json $files "offeremirait:flyer-imports/$D/$f/")
    dir="/home/offeremirait/flyer-imports/$D/$f"
    $SSH "cd ~/offer/backend && nohup $PHP artisan flyers:import-reviewed $dir/manifest.json > $dir/import.log 2>&1 < /dev/null & echo \$! > $dir/import.pid"
    while $SSH "kill -0 \$(cat $dir/import.pid) 2>/dev/null"; do sleep 20; done
    $SSH "cat $dir/import.log"
done
