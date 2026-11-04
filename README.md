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

## Flyer import with AI

Admin → Offers → **Import from flyer (AI)** reads a flyer PDF or page photos into offers; people check them on the review screen before they publish. Stores' websites can also be checked automatically (store → **Flyer sources**, only with the store's permission). Code: `backend/app/Domain/Flyers`.

- **Keys** go in `backend/.env` only: `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`. Provider, model, budget and publishing are chosen in the admin under Offers → **Flyer AI settings**.
- **Workers**: AI calls take minutes, so they run on their own queue connection. Locally set `FLYER_QUEUE_CONNECTION=redis-long` (Horizon runs it). On the server, the cron worker runs `database-long` (`FLYER_WORKERS` adds parallel workers).
- **Schedule**: `flyers:fetch` (5 min), `flyers:batches` (5 min, half-price batch API), `flyers:sweep` (10 min), `flyers:check-stale` (daily).
- **Choosing a model**: once a few flyers are reviewed, compare a cheaper model against what reviewers accepted:
  ```sh
  docker compose exec app php artisan flyers:eval 12 15 --model=claude-haiku-5-5
  ```
- **Server**: PHP needs `pcntl` and `proc_open`, poppler (`pdftoppm`), and uploads up to 64 MB (`upload_max_filesize`, `post_max_size`).

Run tools inside the containers:

```sh
docker compose exec app php artisan migrate
docker compose exec app composer require vendor/package
docker compose exec app php artisan test
docker compose run --rm web pnpm add some-package
```
