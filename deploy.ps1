# Deploy offer.emirait.com.
#
#   .\deploy.ps1           test, build, commit, push to GitHub + live server, deploy the Cloudflare Worker, verify
#   .\deploy.ps1 -Setup    one time: prepare the server account (bare repo, hook, Typesense, cron, docroot)
#   .\deploy.ps1 -SkipTests
#
# Live = the cPanel account offeremirait on server.s26.96h.in (the "offeremirait" entry in ~/.ssh/config).
# Its ~/deploy.git post-receive hook runs infra/server/after-deploy.sh: composer, migrations, caches,
# search index. The website itself is a Cloudflare Worker (web/wrangler.jsonc, env "production"),
# deployed with CLOUDFLARE_API_TOKEN / CLOUDFLARE_ACCOUNT_ID from the root .env.
param([switch]$Setup, [switch]$SkipTests)

# $ErrorActionPreference only covers cmdlets; native tools (git, docker, ssh) report
# failure through $LASTEXITCODE, so every one of them goes through Invoke-Step.
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$Site = "https://offer.emirait.com"
$LiveRemote = "offeremirait:deploy.git"

function Invoke-Step([string]$Label, [scriptblock]$Command) {
    Write-Host $Label
    & $Command
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FAILED: $Label (exit code $LASTEXITCODE)" -ForegroundColor Red
        exit 1
    }
}

# Root .env holds local Docker secrets and the Cloudflare token; never committed.
$envFile = @{}
if (Test-Path ".env") {
    Get-Content ".env" | Where-Object { $_ -match '^\s*([A-Z0-9_]+)=(.*)$' } | ForEach-Object {
        $envFile[$Matches[1]] = $Matches[2].Trim('"')
    }
}

if ($Setup) {
    Invoke-Step "Preparing the server account (infra/server/setup.sh)..." {
        Get-Content "infra/server/setup.sh" -Raw | ssh offeremirait "tr -d '\r' | bash -s"
    }
    git remote get-url live 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Invoke-Step "Adding git remote 'live'..." { git remote add live $LiveRemote }
    }
    Write-Host "Server ready. If you haven't yet, run infra/server/root-setup.sh as root, then .\deploy.ps1" -ForegroundColor Green
    exit 0
}

# Fail before building and committing when the live server cannot be reached, rather than at the last push.
git remote get-url live 2>$null | Out-Null
if ($LASTEXITCODE -ne 0) {
    Write-Host "No 'live' remote. Run .\deploy.ps1 -Setup first." -ForegroundColor Red
    exit 1
}
$liveHost = ((ssh -G offeremirait) -match '^hostname ' | Select-Object -First 1) -replace '^hostname ', ''
Invoke-Step "Checking live server ($liveHost)..." { git ls-remote --heads live | Out-Null }

# Refuse to deploy when another machine has pushed commits this clone lacks,
# otherwise the push is rejected after a build + commit that then need merging.
Invoke-Step "Checking GitHub..." { git fetch origin }
$behind = [int](git rev-list --count HEAD..origin/main)
if ($behind -gt 0) {
    Write-Host "Local main is $behind commit(s) behind origin/main. Run 'git pull', resolve, then deploy again." -ForegroundColor Red
    exit 1
}

if (-not $SkipTests) {
    Invoke-Step "Running backend tests..." { docker compose run --rm app php artisan test --compact }
    Invoke-Step "Running price-comparison tests..." { docker compose run --rm --no-deps -w /repo web pnpm --filter "@offer/basket" test }
}

# Production build: env "production" in web/wrangler.jsonc, browser code pointed at the live site.
Invoke-Step "Building the website..." {
    docker compose run --rm --no-deps -e CLOUDFLARE_ENV=production -e "PUBLIC_API_URL=$Site" web pnpm build
}

Invoke-Step "Adding files to git..." { git add . }
git diff --cached --quiet
if ($LASTEXITCODE -ne 0) {
    Invoke-Step "Committing changes..." { git commit -m "update" }
} else {
    Write-Host "Nothing new to commit."
}

Invoke-Step "Pushing to GitHub..." { git push origin main }
# The post-receive hook prints each step (composer, migrations, caches, search index) here.
Invoke-Step "Pushing to live..." { git push live main }

if ($envFile["CLOUDFLARE_API_TOKEN"] -and $envFile["CLOUDFLARE_ACCOUNT_ID"]) {
    $env:CLOUDFLARE_API_TOKEN = $envFile["CLOUDFLARE_API_TOKEN"]
    $env:CLOUDFLARE_ACCOUNT_ID = $envFile["CLOUDFLARE_ACCOUNT_ID"]
    Invoke-Step "Deploying the Cloudflare Worker..." {
        docker compose run --rm --no-deps -e CLOUDFLARE_API_TOKEN -e CLOUDFLARE_ACCOUNT_ID web pnpm exec wrangler deploy
    }
} else {
    Write-Host "Skipping the Worker: add CLOUDFLARE_API_TOKEN and CLOUDFLARE_ACCOUNT_ID to .env to deploy the website." -ForegroundColor Yellow
}

# Verify the live site, not just the push. A broken route can still answer 200 with an error page,
# so check what each response contains.
Write-Host "Verifying live..."
function Get-Live([string]$Path) {
    try {
        return Invoke-WebRequest -Uri "$Site$Path" -UseBasicParsing -TimeoutSec 30
    } catch {
        Write-Host "FAILED: could not fetch $Site$Path ($($_.Exception.Message))" -ForegroundColor Red
        exit 1
    }
}

$api = Get-Live "/api/v1/categories"
if (-not $api.Content.TrimStart().StartsWith("[")) {
    Write-Host "FAILED: /api/v1/categories did not return JSON; the Worker isn't reaching Laravel." -ForegroundColor Red
    exit 1
}

if ($envFile["CLOUDFLARE_API_TOKEN"]) {
    $home_ = Get-Live "/"
    if ($home_.Content -notmatch "Best deals this week") {
        Write-Host "FAILED: the home page doesn't show the offers grid." -ForegroundColor Red
        exit 1
    }
    # The JavaScript this build produced must be served as JavaScript, not an HTML error page.
    $asset = Get-ChildItem "web/dist/client/_astro" -Filter "*.js" | Select-Object -First 1
    $js = Get-Live "/_astro/$($asset.Name)"
    if ($js.Content.TrimStart().StartsWith("<")) {
        Write-Host "FAILED: /_astro/$($asset.Name) served HTML, not JavaScript." -ForegroundColor Red
        exit 1
    }
    Write-Host "Live is serving this build ($($asset.Name))" -ForegroundColor Green
}

Write-Host "Deployment completed successfully!" -ForegroundColor Green
