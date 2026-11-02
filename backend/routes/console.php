<?php

use App\Domain\Alerts\WeeklyDigest;
use App\Domain\Catalog\EssentialGrades;
use App\Domain\Engagement\Counters;
use App\Domain\Engagement\Likes;
use App\Domain\Geo\ImportPlaceNames;
use App\Domain\Offers\ExpireOffers;
use App\Domain\Offers\Offer;
use App\Domain\Search\OfferDocument;
use App\Domain\Search\RankSync;
use App\Domain\Search\SearchKeys;
use App\Domain\Search\Synonyms;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoNearbySeeder;
use Database\Seeders\DemoOffersSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('offers:expire', function (ExpireOffers $expire) {
    $result = $expire();
    $this->info("Expired {$result['offers']} offers and {$result['brochures']} brochures; {$result['started']} scheduled brochures went live.");
})->purpose('Expire finished offers/brochures and start scheduled ones');

Schedule::command('offers:expire')->dailyAt('00:05')->timezone(config('app.market_timezone'))->withoutOverlapping();

Artisan::command('search:create-key', function (SearchKeys $keys) {
    $this->info('Add this to backend/.env as TYPESENSE_SEARCH_KEY (Typesense shows it only once):');
    $this->line($keys->createSearchOnlyKey());
})->purpose('Create the search-only Typesense key the public website uses');

Artisan::command('likes:sync-search', function (Likes $likes) {
    $this->info('Re-indexed '.$likes->syncSearch().' offers with new like counts.');
})->purpose('Push changed offer like counts to Typesense');

Schedule::command('likes:sync-search')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('search:sync-synonyms', function (Synonyms $synonyms) {
    $this->info('Search has '.$synonyms->sync().' synonym groups.');
})->purpose('Push the admin synonyms and brand spellings to Typesense');

// Saves sync straight away; this catches up after a search outage or a rebuilt collection.
Schedule::command('search:sync-synonyms')->hourly()->withoutOverlapping();

Artisan::command('search:sync-rank', function (RankSync $sync) {
    $result = $sync();
    $this->info("Ranked {$result['products']} products; {$result['offers']} offers updated in search.");
})->purpose('Recompute product popularity and rank, and push changes to Typesense');

Schedule::command('search:sync-rank')->everyThirtyMinutes()->withoutOverlapping();

Artisan::command('catalog:grade-essentials', function (EssentialGrades $grades) {
    $this->info("Graded {$grades()} product groups as daily essentials.");
})->purpose('Give ungraded product groups for daily essentials grade 1');

// New catalog imports leave groups ungraded; a grade set in the admin is never changed.
Schedule::command('catalog:grade-essentials')->dailyAt('03:30')->timezone(config('app.market_timezone'))->withoutOverlapping();

Artisan::command('places:import', function (ImportPlaceNames $import) {
    $this->info("The location search now lists {$import()} places.");
})->purpose('Load the UAE place list for the location search');

Artisan::command('engagement:prune', function (Counters $counters) {
    $this->info("Removed {$counters->prune()} old count rows.");
})->purpose('Delete public view counts older than the retention period');

Schedule::command('engagement:prune')->dailyAt('03:45')->timezone(config('app.market_timezone'))->withoutOverlapping();

Artisan::command('digest:weekly', function (WeeklyDigest $digest) {
    $this->info('Sent '.$digest->send().' weekly digest emails.');
})->purpose('Email each shopper this week\'s deals on products they like');

Schedule::command('digest:weekly')->weeklyOn(0, '09:00')->timezone(config('app.market_timezone'))->withoutOverlapping();

// Without Horizon (shared hosting), the scheduler drains the queues every minute.
if (config('queue.cron_worker')) {
    Schedule::command('queue:work --queue=default,render --stop-when-empty --max-time=50 --tries=3')
        ->everyMinute()->withoutOverlapping()->runInBackground();
}

Artisan::command('demo:seed', function () {
    $this->call('db:seed', ['--class' => DemoCatalogSeeder::class, '--force' => true]);
    $this->call('db:seed', ['--class' => DemoOffersSeeder::class, '--force' => true]);
    $this->call('db:seed', ['--class' => DemoNearbySeeder::class, '--force' => true]);
})->purpose('Load the demo catalog, stores and offers (for staging servers)');

Artisan::command('media:copy {from=public} {--to=}', function (string $from) {
    // Moving product photos between disks (e.g. local "public" → "r2"): copy, then re-index so cards get the new URLs.
    $source = Storage::disk($from);
    $target = Storage::disk($this->option('to') ?: config('filesystems.media_disk'));
    $copied = 0;
    foreach ([...$source->allFiles('products'), ...$source->allFiles('logos')] as $path) {
        if (! $target->exists($path)) {
            $target->put($path, $source->get($path), ['visibility' => 'public', 'CacheControl' => 'public, max-age=31536000, immutable']);
            $copied++;
        }
    }
    $this->info("Copied {$copied} files.");
    Offer::query()->live()->with(OfferDocument::RELATIONS)->chunkById(500, fn ($offers) => Offer::syncSearch($offers));
    $this->info('Re-indexed live offers.');
})->purpose('Copy product photos to the media disk (or --to) and re-index offers');
