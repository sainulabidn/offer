#!/bin/bash
# Upload flyer folders (files + manifest.json) to live and import them.
#   .claude/skills/flyer-import/push.sh 2026-11-17 14-4e3b48d6 68-6fcb69c7 ...
# Uses flyers:import-reviewed when it is deployed, else the uploaded runner (~/flyer-tools/run-import.php).
set -euo pipefail
D="$1"; shift
WORK="$(cd "$(dirname "$0")/../../.." && pwd)/backend/storage/app/flyer-work/$D"
PHP=/opt/cpanel/ea-php84/root/usr/bin/php
paths=()
for f in "$@"; do
    f="${f%/}"
    ssh offeremirait "mkdir -p ~/flyer-imports/$D/$f"
    files=$(MSYS_NO_PATHCONV=1 grep -o '"files": \[[^]]*\]' -z "$WORK/$f/manifest.json" | tr -d '\0' | grep -o '"[^"]*\.\(pdf\|jpe\?g\|png\|webp\)"' | tr -d '"')
    (cd "$WORK/$f" && scp -q manifest.json $files "offeremirait:flyer-imports/$D/$f/")
    paths+=("/home/offeremirait/flyer-imports/$D/$f/manifest.json")
done
if ssh offeremirait "cd ~/offer/backend && $PHP artisan list --raw 2>/dev/null | grep -q '^flyers:import-reviewed'"; then
    ssh offeremirait "cd ~/offer/backend && $PHP artisan flyers:import-reviewed ${paths[*]}"
else
    ssh offeremirait "cd ~/offer/backend && MANIFESTS='${paths[*]}' timeout 1800 $PHP artisan tinker --execute='require \"/home/offeremirait/flyer-tools/run-import.php\";'"
fi
