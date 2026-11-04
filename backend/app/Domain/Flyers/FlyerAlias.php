<?php

namespace App\Domain\Flyers;

use App\Domain\Catalog\ProductVariant;
use App\Domain\Retail\Chain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * "Nesto prints 'Almarai Milk Full Fat 2L' for this pack": learned whenever a reviewer accepts an item,
 * so next week's flyer matches the same product with no AI and no person. Per store; chain_id null is shared.
 */
class FlyerAlias extends Model
{
    protected $guarded = [];

    /** A name as a lookup key: lower case, letters and digits only, single spaces (Arabic kept). */
    public static function key(string $name): string
    {
        return mb_substr(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($name))), 0, 160);
    }

    public static function find(?int $chainId, string $name, string $sizeKey): ?self
    {
        return self::query()
            ->where('key', self::key($name))
            ->where('size_key', $sizeKey)
            ->where(fn ($q) => $q->where('chain_id', $chainId)->orWhereNull('chain_id'))
            ->orderByRaw('chain_id IS NULL') // the store's own first
            ->first();
    }

    public static function learn(int $chainId, string $name, string $sizeKey, int $variantId, ?int $userId): void
    {
        $key = self::key($name);
        if ($key === '') {
            return;
        }
        self::query()->upsert(
            [['chain_id' => $chainId, 'key' => $key, 'size_key' => $sizeKey, 'product_variant_id' => $variantId, 'hits' => 1, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]],
            ['chain_id', 'key', 'size_key'],
            ['product_variant_id', 'hits' => DB::raw('flyer_aliases.hits + 1'), 'updated_at'],
        );
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }
}
