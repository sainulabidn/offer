<?php

namespace App\Domain\Engagement;

use App\Domain\Catalog\Product;
use App\Domain\Offers\Offer;
use App\Domain\Search\OfferDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Likes on products and offers. Counts change atomically in PostgreSQL right away;
 * offers whose count changed are re-indexed in Typesense in batches (likes:sync-search).
 */
final class Likes
{
    public const array TYPES = ['product' => Product::class, 'offer' => Offer::class];

    private const string DIRTY_OFFERS = 'likes:dirty-offers';

    public function target(string $type, int $id): Model
    {
        return (self::TYPES[$type])::query()->findOrFail($id);
    }

    /** @return array{liked: bool, count: int} */
    public function toggle(User $user, Model $target): array
    {
        $like = Like::where(['user_id' => $user->id, 'likeable_type' => $target->getMorphClass(), 'likeable_id' => $target->getKey()])->first();

        DB::transaction(function () use ($like, $user, $target) {
            if ($like) {
                $like->delete();
                $target->newQuery()->whereKey($target->getKey())->where('like_count', '>', 0)->decrement('like_count');
            } else {
                try {
                    Like::create(['user_id' => $user->id, 'likeable_type' => $target->getMorphClass(), 'likeable_id' => $target->getKey()]);
                    $target->newQuery()->whereKey($target->getKey())->increment('like_count');
                } catch (UniqueConstraintViolationException) {
                    // A double tap raced us; the like already exists.
                }
            }
        });

        if ($target instanceof Offer) {
            Cache::lock(self::DIRTY_OFFERS.':lock', 5)->block(5, function () use ($target) {
                Cache::put(self::DIRTY_OFFERS, array_unique([...Cache::get(self::DIRTY_OFFERS, []), $target->getKey()]));
            });
        }

        return ['liked' => ! $like, 'count' => (int) $target->newQuery()->whereKey($target->getKey())->value('like_count')];
    }

    /** Re-index offers whose like count changed. Returns how many were synced. */
    public function syncSearch(): int
    {
        $ids = Cache::lock(self::DIRTY_OFFERS.':lock', 5)->block(5, fn () => tap(Cache::get(self::DIRTY_OFFERS, []), fn () => Cache::forget(self::DIRTY_OFFERS)));
        if (! $ids) {
            return 0;
        }

        Offer::whereKey($ids)->with(OfferDocument::RELATIONS)->get()->searchable();

        return count($ids);
    }

    /** @return array{product: int[], offer: int[]} */
    public function of(User $user): array
    {
        $likes = Like::where('user_id', $user->id)->get(['likeable_type', 'likeable_id']);

        return [
            'product' => $likes->where('likeable_type', 'product')->pluck('likeable_id')->values()->all(),
            'offer' => $likes->where('likeable_type', 'offer')->pluck('likeable_id')->values()->all(),
        ];
    }
}
