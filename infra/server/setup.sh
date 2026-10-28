#!/bin/bash
#
# setup.sh: one-time setup inside the cPanel account (no root) for push-to-deploy. Safe to run again.
# deploy.ps1 -Setup runs it over SSH; afterwards every `git push live main` deploys.
#
#   ~/deploy.git            bare repository; its post-receive hook checks out ~/offer and runs after-deploy.sh
#   ~/offer                 the working copy (backend/public is the docroot)
#   ~/public_html           symlink to ~/offer/backend/public (the old folder is kept as public_html.cpanel)
#   ~/typesense/api-key     key for the Typesense container that root-setup.sh runs (127.0.0.1:8108)
#   crontab                 Laravel scheduler (queue worker included)

set -euo pipefail

APP="$HOME/offer"
REPO="$HOME/deploy.git"
PHP="/opt/cpanel/ea-php84/root/usr/bin/php"

say() { printf '\n== %s\n' "$*"; }

say "bare repository"
[[ -d "$REPO" ]] || git init -q --bare -b main "$REPO"
cat > "$REPO/hooks/post-receive" <<'HOOK'
#!/bin/bash
# Checks out the pushed main branch into ~/offer and runs the deploy steps.
set -euo pipefail
while read -r old new ref; do
    [[ "$ref" == "refs/heads/main" ]] || continue
    mkdir -p "$HOME/offer"
    git --work-tree="$HOME/offer" --git-dir="$HOME/deploy.git" checkout -f main
    bash "$HOME/offer/infra/server/after-deploy.sh" "$old" "$new"
done
HOOK
chmod +x "$REPO/hooks/post-receive"
# after-deploy.sh runs `git -C ~/offer diff`; point that folder at the bare repo.
mkdir -p "$APP"
[[ -e "$APP/.git" ]] || echo "gitdir: $REPO" > "$APP/.git"
echo "   ok $REPO"

say "typesense key"
# Typesense itself runs in Docker (root-setup.sh); this account only holds the key the app uses.
mkdir -p "$HOME/typesense" && chmod 700 "$HOME/typesense"
rm -rf "$HOME/typesense/bin" "$HOME/typesense/data" # leftovers from trying the native binary
[[ -s "$HOME/typesense/api-key" ]] || (umask 077 && openssl rand -hex 24 > "$HOME/typesense/api-key")
echo "   ok ~/typesense/api-key"

say "docroot"
if [[ -L "$HOME/public_html" ]]; then
    echo "   ok public_html -> $(readlink "$HOME/public_html")"
else
    mv "$HOME/public_html" "$HOME/public_html.cpanel"
    mkdir -p "$APP/backend/public"
    ln -s "$APP/backend/public" "$HOME/public_html"
    # Keep AutoSSL's validation folder working.
    [[ -d "$HOME/public_html.cpanel/.well-known" ]] && cp -r "$HOME/public_html.cpanel/.well-known" "$APP/backend/public/" || true
    echo "   ok public_html -> $APP/backend/public (old folder: ~/public_html.cpanel)"
fi

say "crontab"
current="$(crontab -l 2>/dev/null || true)"
add_cron() { grep -qF "$2" <<<"$current" || current="$current"$'\n'"$1 $2"; }
add_cron '* * * * *' "cd $APP/backend && $PHP artisan schedule:run >> /dev/null 2>&1"
printf '%s\n' "$current" | sed '/^$/d' | crontab -
crontab -l | sed 's/^/   /'

echo
echo "Setup done. Push with: git push live main"
