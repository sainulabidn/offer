<?php

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\Product;
use App\Domain\Geo\Region;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Retail\Chain;
use App\Filament\Resources\Brochures\Pages\EditBrochure;
use App\Filament\Resources\Brochures\RelationManagers\OffersRelationManager;
use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Database\Seeders\CatalogStructureSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\GeoSeeder;
use Database\Seeders\StaffSeeder;
use Database\Seeders\UnitSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Livewire\livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-24 09:00:00');
    $this->seed([UnitSeeder::class, GeoSeeder::class, CatalogStructureSeeder::class, StaffSeeder::class, DemoCatalogSeeder::class]);

    $this->chain = Chain::firstOrFail();
    // Second branch in Sharjah, so region scopes have something to exclude.
    $this->chain->branches()->create([
        'name' => ['en' => 'Al Majaz'],
        'slug' => 'al-majaz',
        'region_id' => Region::where('slug', 'al-majaz')->value('id'),
        'location' => Point::makeGeodetic(25.3260, 55.3850),
    ]);
    $this->brochure = Brochure::create([
        'chain_id' => $this->chain->id,
        'title' => ['en' => 'Weekly deals'],
        'slug' => 'lulu-weekly',
        'valid_from' => '2026-10-23',
        'valid_to' => '2026-10-29',
    ]);
    $this->riceFiveKg = Product::where('slug', 'india-gate-classic-basmati-rice')->firstOrFail()->variants()->where('slug', '5-kg')->firstOrFail();
});

it('publishes offers to every branch of the chain when there are no scope rules', function () {
    Offer::create(['brochure_id' => $this->brochure->id, 'product_variant_id' => $this->riceFiveKg->id, 'price' => '45.95']);

    $count = app(PublishBrochure::class)($this->brochure);

    expect($count)->toBe(1)
        ->and($this->brochure->fresh()->status)->toBe(BrochureStatus::Published)
        ->and($this->brochure->branches()->count())->toBe(2)
        ->and(Offer::first()->status)->toBe(OfferStatus::Published);
});

it('limits a brochure to one emirate', function () {
    Offer::create(['brochure_id' => $this->brochure->id, 'product_variant_id' => $this->riceFiveKg->id, 'price' => '45.95']);
    $this->brochure->scopes()->create(['scope_type' => 'region', 'scope_id' => Region::where('slug', 'dubai')->value('id')]);

    app(PublishBrochure::class)($this->brochure);

    expect($this->brochure->branches()->pluck('slug')->all())->toBe(['al-barsha']);
});

it('schedules a brochure that starts in the future', function () {
    $this->brochure->update(['valid_from' => '2026-10-30', 'valid_to' => '2026-11-05']);
    Offer::create(['brochure_id' => $this->brochure->id, 'product_variant_id' => $this->riceFiveKg->id, 'price' => '45.95']);

    app(PublishBrochure::class)($this->brochure);

    expect($this->brochure->fresh()->status)->toBe(BrochureStatus::Scheduled);
});

it('refuses to publish an empty brochure', function () {
    app(PublishBrochure::class)($this->brochure);
})->throws(RuntimeException::class, 'no offers');

it('enters an offer through the brochure page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('data_operator');
    actingAs($operator);

    get("/admin/brochures/{$this->brochure->id}/edit")->assertOk();

    livewire(OffersRelationManager::class, ['ownerRecord' => $this->brochure, 'pageClass' => EditBrochure::class])
        ->callAction(TestAction::make(CreateAction::class)->table(), data: [
            'product_variant_id' => $this->riceFiveKg->id,
            'price' => '45.95',
            'regular_price' => '54.50',
            'promo_type' => 'price',
            'status' => 'draft',
        ])
        ->assertHasNoFormErrors();

    expect(Offer::firstOrFail()->unit_price)->toBe('9.1900');
});
