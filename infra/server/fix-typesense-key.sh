#!/bin/bash
#
# fix-typesense-key.sh: take the Typesense master key off the command line.
#
# The container was started with `--api-key <key>`, and command-line arguments are visible in `ps`
# to every account on this shared server. This recreates the container so the key comes from a
# root-only env file instead. Same image, same data (/var/lib/offer-typesense), same key, same port:
# nothing has to change in any app. Search is down for a few seconds while it restarts
# (lazyoffer.com shows no results; the Lead10 CRM falls back to its database search by itself).
#
# Safe to run again. Run as root on the server:   bash fix-typesense-key.sh

set -u
set -o pipefail

TS_NAME="offer-typesense"
TS_DATA="/var/lib/offer-typesense"
TS_ENV="/etc/offer-typesense.env"
KEY_FILE="/home/offeremirait/typesense/api-key"

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run this as root."
docker inspect "$TS_NAME" >/dev/null 2>&1 || die "No container named $TS_NAME."
[[ -d "$TS_DATA" ]] || die "Data directory $TS_DATA is missing."

image="$(docker inspect -f '{{.Config.Image}}' "$TS_NAME")"
args="$(docker inspect -f '{{join .Args " "}}' "$TS_NAME")"

if [[ "$args" != *"--api-key"* ]]; then
    echo "ok       the key is not on the command line; nothing to do."
    exit 0
fi

# The key the container runs with now (so every existing app key keeps working).
key="$(printf '%s' "$args" | sed -n 's/.*--api-key \([^ ]*\).*/\1/p')"
[[ -n "$key" ]] || die "Could not read the current key from the container."
if [[ -s "$KEY_FILE" && "$(cat "$KEY_FILE")" != "$key" ]]; then
    die "The running key differs from $KEY_FILE. Stopping; nothing was changed."
fi

(umask 077 && printf 'TYPESENSE_API_KEY=%s\nTYPESENSE_DATA_DIR=/data\nTYPESENSE_ENABLE_CORS=false\n' "$key" > "$TS_ENV")
chown root:root "$TS_ENV"
chmod 600 "$TS_ENV"
echo "wrote    $TS_ENV (root only). This is also root's copy of the master key."

docker rm -f "$TS_NAME" >/dev/null || die "Could not remove the old container."
docker run -d --name "$TS_NAME" --restart unless-stopped \
    -p 127.0.0.1:8108:8108 -v "$TS_DATA:/data" --env-file "$TS_ENV" \
    "$image" >/dev/null || die "Could not start the new container. Start it by hand: docker run … --env-file $TS_ENV $image"
echo "started  $TS_NAME ($image)"

for _ in $(seq 1 60); do curl -fs http://127.0.0.1:8108/health 2>/dev/null | grep -q '"ok":true' && break; sleep 1; done
if curl -fs http://127.0.0.1:8108/health 2>/dev/null | grep -q '"ok":true'; then
    echo "ok       Typesense answers on 127.0.0.1:8108"
else
    die "Typesense is not answering yet: docker logs $TS_NAME"
fi

count="$(curl -fs http://127.0.0.1:8108/collections -H "X-TYPESENSE-API-KEY: $key" | grep -o '"name":"[^"]*","num_documents"' | wc -l)"
echo "ok       $count collections loaded"

if ps -eo args | grep -v grep | grep -q -- "--api-key"; then
    echo "WARNING  a process still shows --api-key in ps"
else
    echo "ok       the key no longer shows in the process list"
fi
