<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureItem;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Catalog\PackSize;
use App\Domain\Offers\DirectOffers;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Offers\PriceWait;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A reviewer's decision on flyer items. Accepting makes (or updates) a draft offer on the brochure, which
 * the brochure's Publish button takes live, and teaches the matcher this name and size (FlyerAlias).
 * Store users' price wait applies to whoever accepts (PriceWait), checked here because jobs have no signed-in user.
 */
final class AcceptItems
{
    public function __construct(private readonly DirectOffers $offers) {}

    /** @throws ValidationException when the item can't become an offer yet */
    public function accept(BrochureItem $item, ?User $user): Offer
    {
        if ($item->product_variant_id === null || $item->price === null) {
            throw ValidationException::withMessages(['item' => 'Pick the product and price first.']);
        }
        $brochure = $item->loadMissing(['brochure', 'offer'])->brochure;
        $offer = $item->offer ?? new Offer;
        $attributes = [
            'brochure_id' => $brochure->id,
            'brochure_page_id' => $item->brochure_page_id,
            'chain_id' => $brochure->chain_id,
            'product_variant_id' => $item->product_variant_id,
            'price' => $item->price,
            'regular_price' => $item->regular_price,
            'promo_type' => $item->promo_type,
            'promo_params' => $item->promo_params ?: [],
            'promo_label' => $item->getTranslations('promo_label') ?: null,
            'condition_label' => $item->getTranslations('condition_label') ?: null,
            'conditions' => $item->conditions ?: [],
            'valid_from' => $item->valid_from,
            'valid_to' => $item->valid_to,
            'bbox' => $item->bbox,
            'confidence' => $item->confidence,
            'raw_text' => $item->raw,
        ];
        $offer->fill($attributes);
        if (! $offer->exists || $offer->isDirty(Offer::PRICE_FIELDS)) {
            PriceWait::check($user, $brochure->chain_id, $item->product_variant_id);
        }

        return DB::transaction(function () use ($item, $offer, $user, $brochure) {
            if (! $offer->exists) {
                $offer->status = OfferStatus::Draft;
                $offer->created_by = $user?->id;
            }
            $offer->save();
            $item->update(['status' => ItemStatus::Accepted, 'offer_id' => $offer->id, 'reviewed_by' => $user?->id, 'reviewed_at' => now()]);
            FlyerAlias::learn($brochure->chain_id, $item->name, PackSize::parse($item->size_text)?->key() ?? '', $item->product_variant_id, $user?->id);

            return $offer;
        });
    }

    /**
     * Accepts every matched item with nothing to check and enough AI confidence.
     *
     * @return array{accepted: int, failed: array<int, string>} failed: item id => why
     */
    public function acceptClean(Brochure $brochure, ?User $user): array
    {
        $min = (float) FlyerSettings::get('auto_min_confidence');
        $result = ['accepted' => 0, 'failed' => []];
        foreach ($brochure->items()->where('status', ItemStatus::Matched)->with('brochure')->get() as $item) {
            if (! $item->isReadyToAccept($min)) {
                continue;
            }
            try {
                $this->accept($item, $user);
                $result['accepted']++;
            } catch (ValidationException $e) {
                $result['failed'][$item->id] = collect($e->errors())->flatten()->first() ?? $e->getMessage();
            }
        }

        return $result;
    }

    /** Leaves the item out; its draft offer goes too, or comes off the website if it was published. */
    public function ignore(BrochureItem $item, ?User $user): void
    {
        DB::transaction(function () use ($item, $user) {
            if ($offer = $item->loadMissing('offer')->offer) {
                $offer->status === OfferStatus::Published ? $this->offers->withdraw($offer) : $offer->delete();
            }
            $item->update(['status' => ItemStatus::Ignored, 'offer_id' => null, 'reviewed_by' => $user?->id, 'reviewed_at' => now()]);
        });
    }
}
