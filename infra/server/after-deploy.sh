#!/bin/bash
#
# after-deploy.sh: run by ~/deploy.git/hooks/post-receive after each push, in ~/offer.
# First run also creates .env, seeds the admin and demo data, and builds the search index.
#
#   after-deploy.sh OLDREV NEWREV

set -euo pipefail

OLD="${1:-0000000000000000000000000000000000000000}"
NEW="${2:-HEAD}"
APP="$HOME/offer"
PHP="/opt/cpanel/ea-php84/root/usr/bin/php"
COMPOSER="$PHP /usr/local/bin/composer"
cd "$APP/backend"

step() { printf '\n-> %s\n' "$*"; }
artisan() { "$PHP" artisan "$@" --no-interaction --no-ansi; }
env_set() { # key value: replace the line, or append it
    if grep -q "^$1=" .env; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}

first_run=0
if [[ ! -f .env ]]; then
    step "creating .env"
    first_run=1
    [[ -f "$HOME/.offer-db.env" ]] || { echo "Missing ~/.offer-db.env: run infra/server/root-setup.sh as root first." >&2; exit 1; }
    (umask 077 && cp "$APP/infra/server/env.production" .env)
    cat "$HOME/.offer-db.env" >> .env
    env_set TYPESENSE_API_KEY "$(cat "$HOME/typesense/api-key")"
fi

step "composer install"
$COMPOSER install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

if ! grep -q '^APP_KEY=base64:' .env; then
    artisan key:generate --force
fi

step "migrations"
artisan migrate --force
# New admin permissions appear with new sections; roles edited in the admin keep their settings.
artisan db:seed --class=AccessSeeder --force

# Typesense runs in Docker (root-setup.sh). Search pages need it; say so plainly instead of half-deploying.
if ! curl -fs http://127.0.0.1:8108/health >/dev/null; then
    echo "Typesense is not answering on 127.0.0.1:8108. As root: docker start offer-typesense (or run root-setup.sh)." >&2
    exit 1
fi

if [[ $first_run -eq 1 ]] || ! grep -q '^TYPESENSE_SEARCH_KEY=.' .env; then
    step "search-only key for browsers"
    artisan config:clear
    key="$(artisan search:create-key | tail -1)"
    env_set TYPESENSE_SEARCH_KEY "$key"
fi

if [[ $first_run -eq 1 ]]; then
    step "admin user and demo data"
    artisan config:clear
    (umask 077 && artisan db:seed --force | tee "$HOME/offer-admin-login.txt" | grep -v "Admin created" || true)
    echo "   admin login saved to ~/offer-admin-login.txt"
    artisan demo:seed
fi

step "caches"
artisan storage:link >/dev/null 2>&1 || true
artisan optimize
artisan filament:optimize

# Rebuild the search index on the first deploy and whenever the document shape changed.
if [[ $first_run -eq 1 ]] || git --git-dir="$HOME/deploy.git" diff --name-only "$OLD" "$NEW" 2>/dev/null | grep -q 'Search/OfferDocument.php'; then
    step "search index"
    artisan scout:flush 'App\Domain\Offers\Offer'
    artisan scout:import 'App\Domain\Offers\Offer'
fi
artisan search:sync-synonyms

artisan queue:restart
echo
echo "Deployed $(git --git-dir="$HOME/deploy.git" log -1 --format='%h %s')"
