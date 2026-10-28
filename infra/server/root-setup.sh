#!/bin/bash
#
# root-setup.sh: one-time root steps for offer.emirait.com (cPanel account offeremirait) on server.s26.96h.in.
# Safe to run again: it only adds what is missing. Prints no passwords.
#
#   1. PHP 8.4 (ea-php84) for the offer.emirait.com vhost
#   2. PostGIS for PostgreSQL 18, libvips (product image sizes) and poppler (brochure PDFs)
#   3. PostgreSQL role + database with the postgis, pg_trgm, unaccent and btree_gist extensions
#   4. ~offeremirait/.offer-db.env with the database credentials (mode 600), read by the deploy hook
#   5. Typesense 30.2 in Docker, published on 127.0.0.1:8108 only (its Linux binary needs glibc 2.30;
#      AlmaLinux 8 has 2.28), restarting with the server. API key: ~offeremirait/typesense/api-key
#
# Run as root on the server:   bash root-setup.sh

set -u
set -o pipefail

ACCOUNT="offeremirait"
VHOST="offer.emirait.com"
DB="offeremirait_offer"
ROLE="offeremirait"
CRED_FILE="/home/${ACCOUNT}/.offer-db.env"

die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
say()  { printf '\n== %s\n' "$*"; }
note() { printf '   %s\n' "$*"; }

[[ $EUID -eq 0 ]] || die "Run this as root."
id "$ACCOUNT" >/dev/null 2>&1 || die "No account $ACCOUNT on this server."

say "PHP 8.4 for $VHOST"
if whmapi1 php_set_vhost_versions version=ea-php84 vhost="$VHOST" 2>&1 | grep -q 'result: 1'; then
    note "ok       $VHOST uses ea-php84"
else
    note "FAILED   set it in WHM → MultiPHP Manager → $VHOST → PHP 8.4"
fi

say "packages"
# postgis36_18 comes from the PGDG repo that already provides postgresql18; vips-tools from EPEL.
rpm -q epel-release >/dev/null 2>&1 || dnf -y -q install epel-release
dnf -y -q install postgis36_18 vips-tools poppler-utils >/dev/null 2>&1 || note "dnf reported a problem; check the lines below"
for p in postgis36_18 vips-tools poppler-utils; do
    rpm -q "$p" >/dev/null 2>&1 && note "ok       $p" || note "MISSING  $p"
done
if command -v vips >/dev/null 2>&1; then
    tmp="$(mktemp -d)"
    vips black "$tmp/a.png" 64 64 >/dev/null 2>&1
    vips thumbnail "$tmp/a.png" "$tmp/a.webp" 32 >/dev/null 2>&1 && note "ok       vips writes WebP" || note "MISSING  vips WebP support"
    vips thumbnail "$tmp/a.png" "$tmp/a.avif" 32 >/dev/null 2>&1 && note "ok       vips writes AVIF" || note "no AVIF  (WebP only; images still work)"
    rm -rf "$tmp"
fi

say "PostgreSQL role and database"
psql_su() { su - postgres -c "psql -X -q -v ON_ERROR_STOP=1 $*"; }
systemctl is-active postgresql-18 >/dev/null 2>&1 || systemctl is-active postgresql >/dev/null 2>&1 || die "PostgreSQL is not running."
port="$(su - postgres -c "psql -X -At -c 'show port'")"

if [[ -f "$CRED_FILE" ]] && grep -q '^DB_PASSWORD=.' "$CRED_FILE"; then
    password="$(grep '^DB_PASSWORD=' "$CRED_FILE" | cut -d= -f2-)"
    note "keeping the password already in $CRED_FILE"
else
    password="$(openssl rand -hex 24)"
fi

psql_su <<SQL
DO \$\$ BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${ROLE}') THEN
        CREATE ROLE ${ROLE} LOGIN PASSWORD '${password}';
    ELSE
        ALTER ROLE ${ROLE} LOGIN PASSWORD '${password}';
    END IF;
END \$\$;
SQL
if [[ "$(su - postgres -c "psql -X -At -c \"SELECT 1 FROM pg_database WHERE datname = '${DB}'\"")" != "1" ]]; then
    su - postgres -c "createdb -O ${ROLE} ${DB}" && note "created  database $DB"
else
    note "ok       database $DB exists"
fi
# postgis needs a superuser; creating them here means the app's own migration only finds them present.
su - postgres -c "psql -X -q -v ON_ERROR_STOP=1 -d ${DB}" <<'SQL'
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS btree_gist;
SQL
note "extensions: $(su - postgres -c "psql -X -At -d ${DB} -c \"SELECT string_agg(extname || ' ' || extversion, ', ' ORDER BY extname) FROM pg_extension\"")"

umask 077
cat > "$CRED_FILE" <<EOF
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=${port}
DB_DATABASE=${DB}
DB_USERNAME=${ROLE}
DB_PASSWORD=${password}
EOF
chown "$ACCOUNT:$ACCOUNT" "$CRED_FILE"
chmod 600 "$CRED_FILE"
note "wrote    $CRED_FILE (owner $ACCOUNT, mode 600)"

say "login check as $ROLE over 127.0.0.1:$port"
if PGPASSWORD="$password" psql -X -At -h 127.0.0.1 -p "$port" -U "$ROLE" -d "$DB" -c "SELECT postgis_lib_version()" >/dev/null 2>&1; then
    note "ok       $ROLE can log in and sees PostGIS"
else
    note "FAILED   $ROLE cannot log in over TCP: add 'host ${DB} ${ROLE} 127.0.0.1/32 scram-sha-256' to pg_hba.conf and reload"
fi

say "Typesense (Docker, 127.0.0.1:8108 only)"
TS_IMAGE="typesense/typesense:30.2"
TS_NAME="offer-typesense"
TS_DATA="/var/lib/offer-typesense"
KEY_FILE="/home/${ACCOUNT}/typesense/api-key"
systemctl enable --now docker >/dev/null 2>&1 || die "Could not start Docker."
if [[ ! -s "$KEY_FILE" ]]; then
    install -d -o "$ACCOUNT" -g "$ACCOUNT" -m 700 "/home/${ACCOUNT}/typesense"
    (umask 077 && openssl rand -hex 24 > "$KEY_FILE")
    chown "$ACCOUNT:$ACCOUNT" "$KEY_FILE"
fi
key="$(cat "$KEY_FILE")"
mkdir -p "$TS_DATA"
# Recreate when the image or key changed; otherwise just make sure it runs.
current="$(docker inspect -f '{{.Config.Image}} {{join .Args " "}}' "$TS_NAME" 2>/dev/null || true)"
if [[ "$current" != "$TS_IMAGE "*"--api-key $key"* ]]; then
    docker rm -f "$TS_NAME" >/dev/null 2>&1 || true
    docker run -d --name "$TS_NAME" --restart unless-stopped \
        -p 127.0.0.1:8108:8108 -v "$TS_DATA:/data" \
        "$TS_IMAGE" --data-dir /data --api-key "$key" --enable-cors=false >/dev/null
    note "started  $TS_NAME ($TS_IMAGE)"
else
    docker start "$TS_NAME" >/dev/null 2>&1 || true
    note "ok       $TS_NAME already set up"
fi
for _ in $(seq 1 30); do curl -fs http://127.0.0.1:8108/health >/dev/null 2>&1 && break; sleep 1; done
if curl -fs http://127.0.0.1:8108/health 2>/dev/null | grep -q '"ok":true'; then
    note "ok       Typesense answers on 127.0.0.1:8108"
else
    note "FAILED   Typesense is not answering: docker logs $TS_NAME"
fi
echo
