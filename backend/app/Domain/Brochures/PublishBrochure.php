<?php

namespace App\Domain\Brochures;

use App\Domain\Geo\Region;
use App\Domain\Alerts\MatchAlerts;
use App\Domain\Offers\DealScorer;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Search\OfferDocument;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Publishes a reviewed brochure: resolves its branch scope, publishes its draft/approved offers,
 * scores each deal against its 90-day history, marks the brochure published (or scheduled when it
 * starts later), indexes the offers in Typesense and queues alert matching.
 */
final class PublishBrochure
{
    public function __construct(private readonly DealScorer $scorer) {}

    /** @return int number of offers published */
    public function __invoke(Brochure $brochure): int
    {
        if ($brochure->offers()->doesntExist()) {
            throw new RuntimeException('This brochure has no offers yet.');
        }

        $published = DB::transaction(function () use ($brochure) {
            $brochure->branches()->sync($this->branchIds($brochure));

            $pending = $brochure->offers()->whereIn('status', [OfferStatus::Draft, OfferStatus::Approved])->get();
            foreach ($pending as $offer) {
                $this->scorer->score($offer);
                // Quiet save: no Scout sync per offer; the whole brochure is indexed in one batch below.
                $offer->forceFill(['status' => OfferStatus::Published, 'published_at' => now()])->saveQuietly();
            }
            $published = $pending->count();

            $brochure->forceFill([
                'status' => $brochure->valid_from->isFuture() ? BrochureStatus::Scheduled : BrochureStatus::Published,
                'published_at' => $brochure->published_at ?? now(),
            ])->save();

            return $published;
        });

        // Bulk updates skip model events, so push the live offers to Typesense explicitly.
        Offer::query()
            ->where('brochure_id', $brochure->id)
            ->where('status', OfferStatus::Published)
            ->with(OfferDocument::RELATIONS)
            ->get()
            ->searchable();

        // Tell shoppers whose alerts or liked products just went on offer (queued; one email per person).
        if ($published > 0) {
            MatchAlerts::dispatch($brochure->id);
        }

        return $published;
    }

    /** Scope rules → active branch IDs. No rules means every branch of the brochure's chain. */
    public function branchIds(Brochure $brochure): array
    {
        $scopes = DB::table('brochure_scopes')->where('brochure_id', $brochure->id)->get(['scope_type', 'scope_id']);
        if ($scopes->isEmpty()) {
            return Branch::where('chain_id', $brochure->chain_id)->where('is_active', true)->pluck('id')->all();
        }

        $query = Branch::query()->where('is_active', true)->where(function (Builder $q) use ($scopes, $brochure) {
            foreach ($scopes as $scope) {
                match ($scope->scope_type) {
                    'chain' => $q->orWhere('chain_id', $scope->scope_id),
                    'group' => $q->orWhereIn('chain_id', Chain::where('retail_group_id', $scope->scope_id)->select('id')),
                    'branch' => $q->orWhere('id', $scope->scope_id),
                    // An emirate includes its areas; limited to the brochure's own chain.
                    'region' => $q->orWhere(fn (Builder $r) => $r
                        ->where('chain_id', $brochure->chain_id)
                        ->whereIn('region_id', Region::where('id', $scope->scope_id)->orWhere('parent_id', $scope->scope_id)->select('id'))),
                    default => throw new RuntimeException("Unknown brochure scope [{$scope->scope_type}]."),
                };
            }
        });

        return $query->pluck('id')->all();
    }
}
