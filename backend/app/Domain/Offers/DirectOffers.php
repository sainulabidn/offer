<?php

namespace App\Domain\Offers;

use App\Domain\Alerts\MatchAlerts;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Retail\Chain;
use App\Domain\Search\OfferDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Offers a store or our team adds on the Offers page or by CSV, without a brochure. They go live at once.
 *
 * Each sits in a hidden "direct" brochure (Brochure::TYPE_DIRECT), one per store and set of branches, so
 * branches, dates, search and alerts work exactly as for brochure offers. A new price for a pack replaces
 * the earlier direct offer for that pack and branches. Store users wait between prices (PriceWait).
 */
final class DirectOffers
{
    /** Offers added one after another share one alert run, so a CSV sends each shopper one email. */
    private const int ALERT_DELAY_MINUTES = 2;

    public function __construct(private readonly PublishBrochure $publisher) {}

    /**
     * @param  array<string, mixed>  $attributes  chain_id, product_variant_id, price, valid_from, valid_to and the optional offer fields
     * @param  list<int>  $branchIds  empty means every branch of the store
     */
    public function add(array $attributes, array $branchIds, ?User $user): Offer
    {
        $chainId = (int) $attributes['chain_id'];
        PriceWait::check($user, $chainId, (int) $attributes['product_variant_id']);

        [$offer, $replaced] = DB::transaction(function () use ($attributes, $branchIds, $chainId, $user) {
            $container = $this->container($chainId, $branchIds, $user);
            $this->cover($container, (string) $attributes['valid_from'], (string) $attributes['valid_to']);

            $replaced = $container->offers()
                ->where('product_variant_id', $attributes['product_variant_id'])
                ->whereIn('status', [OfferStatus::Draft, OfferStatus::Approved, OfferStatus::Published])
                ->get();
            $replaced->each(fn (Offer $old) => $old->forceFill(['status' => OfferStatus::Withdrawn])->saveQuietly());

            $offer = new Offer([...$attributes, 'brochure_id' => $container->id, 'status' => OfferStatus::Draft]);
            $offer->created_by = $user?->id;
            $offer->save();
            $this->publisher->publishOffers($container, new EloquentCollection([$offer]));

            return [$offer, $replaced];
        });

        $replaced->unsearchable();
        $this->index($offer);

        return $offer;
    }

    /** Changes a direct offer. Its store and branches stay; add a new offer to change those. */
    public function update(Offer $offer, array $attributes, ?User $user): Offer
    {
        unset($attributes['chain_id'], $attributes['branch_ids']);
        // Hidden promotion fields come back empty; that is no change to the price.
        $attributes['promo_params'] = array_filter($attributes['promo_params'] ?? [], fn ($value) => $value !== null && $value !== '');
        $offer->fill($attributes);
        if ($offer->isDirty(Offer::PRICE_FIELDS)) {
            PriceWait::check($user, $offer->chain_id, $offer->product_variant_id);
        }

        DB::transaction(function () use ($offer) {
            $priced = $offer->isDirty(Offer::PRICE_FIELDS);
            $offer->save(); // derives prices and restarts the wait
            if ($priced && $offer->status === OfferStatus::Published) {
                app(DealScorer::class)->score($offer);
                $offer->saveQuietly();
            }
            $this->cover($offer->brochure, $offer->valid_from->toDateString(), $offer->valid_to->toDateString());
            $this->publisher->publishOffers($offer->brochure, new EloquentCollection);
        });

        $this->index($offer);

        return $offer;
    }

    /** Takes any offer off the website. */
    public function withdraw(Offer $offer): void
    {
        $offer->forceFill(['status' => OfferStatus::Withdrawn])->saveQuietly();
        $offer->unsearchable();
    }

    /** The store's direct brochure for exactly these branches, made on first use. */
    public function container(int $chainId, array $branchIds, ?User $user = null): Brochure
    {
        $branchIds = collect($branchIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        $existing = Brochure::query()
            ->where('chain_id', $chainId)
            ->where('type', Brochure::TYPE_DIRECT)
            ->with('scopes')
            ->get()
            ->first(fn (Brochure $brochure) => $brochure->scopes->where('scope_type', 'branch')->pluck('scope_id')->map(fn ($id) => (int) $id)->sort()->values()->all() === $branchIds);
        if ($existing) {
            return $existing;
        }

        $chain = Chain::findOrFail($chainId);
        $today = now(config('app.market_timezone'))->toDateString();
        $brochure = new Brochure([
            'chain_id' => $chainId,
            'type' => Brochure::TYPE_DIRECT,
            'channel' => 'in_store',
            'title' => ['en' => $branchIds ? "{$chain->name} offers ({$this->branchCount($branchIds)})" : "{$chain->name} offers (all branches)"],
            'slug' => Str::slug("{$chain->slug} direct").($branchIds ? '-'.Str::lower(Str::random(6)) : ''),
            'valid_from' => $today,
            'valid_to' => $today,
        ]);
        $brochure->created_by = $user?->id;
        $brochure->save();
        foreach ($branchIds as $branchId) {
            $brochure->scopes()->create(['scope_type' => 'branch', 'scope_id' => $branchId]);
        }

        return $brochure;
    }

    private function branchCount(array $branchIds): string
    {
        return count($branchIds).' '.Str::plural('branch', count($branchIds));
    }

    /**
     * Stretches the container's dates over this offer and its other running or coming offers; the website
     * finds a store's offers by these. Offers that ended no longer hold the start date back.
     */
    private function cover(Brochure $container, string $from, string $to): void
    {
        $today = now(config('app.market_timezone'))->toDateString();
        $earliest = $container->offers()
            ->whereIn('status', [OfferStatus::Draft, OfferStatus::Approved, OfferStatus::Published])
            ->whereDate('valid_to', '>=', $today)
            ->min('valid_from');

        $container->valid_from = $earliest ? min(substr((string) $earliest, 0, 10), $from) : $from;
        $container->valid_to = max($container->valid_to->toDateString(), $to);
        $container->save();
    }

    private function index(Offer $offer): void
    {
        Offer::syncSearch(Offer::whereKey($offer->id)->with(OfferDocument::RELATIONS)->get());
        MatchAlerts::dispatch($offer->brochure_id)->delay(now()->addMinutes(self::ALERT_DELAY_MINUTES));
    }
}
