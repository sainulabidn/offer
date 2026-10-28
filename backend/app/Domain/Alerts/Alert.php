<?php

namespace App\Domain\Alerts;

use App\Models\User;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Tell me when this is on offer": target_type is product, variant or group (any brand of a product group).
 * Optional conditions: price per unit at most max_unit_price, discount at least min_discount, within radius_km.
 */
class Alert extends Model
{
    public const array TARGETS = ['product', 'variant', 'group'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'location' => Point::class,
            'max_unit_price' => 'decimal:4',
            'is_active' => 'boolean',
            'last_notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
