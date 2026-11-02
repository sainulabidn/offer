<?php

namespace App\Domain\Offers;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Retail\Chain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A store's normal shelf price for one pack on one day. Product cards show the latest one, with its
 * date, when that store has no offer on the item. Comes from brochure "was" prices or staff entry.
 */
class RegularPrice extends Model
{
    use Audited, RecordsCreator;

    public const string SOURCE_WAS_PRICE = 'was_price';

    public const string SOURCE_STAFF = 'staff';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'observed_at' => 'date',
        ];
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
