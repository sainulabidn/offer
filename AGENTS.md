# offer — notes for AI agents and developers

Everything runs in Docker (`compose.yaml` at the repo root). Do not install PHP, Composer or Node on the host.

- PHP / Laravel: `docker compose run --rm app <command>` (or `exec app` when the stack is up), e.g. `php artisan test`, `composer require …`
- Website (Astro): `docker compose run --rm web pnpm <command>`
- `backend/vendor` and all `node_modules` live in Docker volumes; the Windows file share is too slow for them.
- Tests run on PostgreSQL (`offer_test` database), never SQLite: the schema uses PostGIS, daterange and temporal constraints.
- Domain code lives in `backend/app/Domain/<Module>`; admin screens in `backend/app/Filament`.
- Translatable columns are JSON `{"en": …, "ar": …}`; in Filament forms use `name.en` / `name.ar` (see `App\Filament\Support\Fields`).
- pnpm 12 enforces a minimum package release age and blocks install scripts; allow scripts only in `pnpm-workspace.yaml` → `allowBuilds`.
