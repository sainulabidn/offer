# offer

Offer comparison platform for UAE supermarket brochures: a product catalog with variants, store offers listed like an online shop, unit-price comparison, radius search and shopping plans.

| Folder | What |
|---|---|
| `backend/` | Laravel 13 (PHP 8.5, Octane on FrankenPHP): admin (Filament), API, brochure pipeline |
| `web/` | Astro 7 + React public website (Cloudflare Workers) |
| `packages/` | Shared TypeScript: plan optimizer, types, test fixtures |
| `infra/` | Dockerfiles and deployment config |

## Local development (Docker only)

Everything runs in containers, so you only need Docker Desktop.

First time:

```sh
cp .env.example .env                                   # then set real secrets
cp backend/.env.example backend/.env
docker compose build
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed   # prints the admin password once
docker compose exec -T postgres psql -U offer -c "CREATE DATABASE offer_test"   # for tests
docker compose run --rm app php artisan search:create-key   # put the printed key in backend/.env as TYPESENSE_SEARCH_KEY
```

After changing the search document (`backend/app/Domain/Search/OfferDocument.php`), rebuild the index:

```sh
docker compose run --rm app php artisan scout:flush "App\Domain\Offers\Offer"
docker compose run --rm app php artisan scout:import "App\Domain\Offers\Offer"
```

After editing routes or config, restart the app (`docker compose restart app`); the PHP code cache doesn't always see changes over the Windows file share.

Every day:

```sh
docker compose up -d                 # app, postgres, redis, typesense, mailpit
docker compose --profile workers up -d   # + horizon, scheduler
docker compose --profile web up -d       # + Astro dev server
```

| URL | Service |
|---|---|
| http://localhost:8000 | Laravel app |
| http://localhost:8000/admin | Filament admin |
| http://localhost:4321 | Public website (Astro) |
| http://localhost:8025 | Mailpit (local email) |

Run tools inside the containers:

```sh
docker compose exec app php artisan migrate
docker compose exec app composer require vendor/package
docker compose exec app php artisan test
docker compose run --rm web pnpm add some-package
```
