<?php

use App\Domain\Offers\ExpireOffers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('offers:expire', function (ExpireOffers $expire) {
    $result = $expire();
    $this->info("Expired {$result['offers']} offers and {$result['brochures']} brochures; {$result['started']} scheduled brochures went live.");
})->purpose('Expire finished offers/brochures and start scheduled ones');

Schedule::command('offers:expire')->dailyAt('00:05')->timezone(config('app.market_timezone'))->withoutOverlapping();

Artisan::command('search:create-key', function (App\Domain\Search\SearchKeys $keys) {
    $this->info('Add this to backend/.env as TYPESENSE_SEARCH_KEY (Typesense shows it only once):');
    $this->line($keys->createSearchOnlyKey());
})->purpose('Create the search-only Typesense key the public website uses');

Artisan::command('likes:sync-search', function (App\Domain\Engagement\Likes $likes) {
    $this->info('Re-indexed '.$likes->syncSearch().' offers with new like counts.');
})->purpose('Push changed offer like counts to Typesense');

Schedule::command('likes:sync-search')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('digest:weekly', function (App\Domain\Alerts\WeeklyDigest $digest) {
    $this->info('Sent '.$digest->send().' weekly digest emails.');
})->purpose('Email each shopper this week\'s deals on products they like');

Schedule::command('digest:weekly')->weeklyOn(0, '09:00')->timezone(config('app.market_timezone'))->withoutOverlapping();
