<?php

namespace App\Domain\Shopper;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved place such as Home or Office. The id is made on the device, like a plan's, so a guest's
 * place keeps it when it reaches the account. Coordinates are rounded to about 110 m: exact home
 * and office locations are sensitive, and store distances don't need them.
 */
class ShopperPlace extends Model
{
    public const int MAX_PER_SHOPPER = 10;

    public const array KINDS = ['home', 'work', 'other'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'location' => Point::class,
            'is_default' => 'boolean',
            'is_auto' => 'boolean',
            'use_score' => 'float',
            'used_at' => 'datetime',
        ];
    }

    public static function pointAt(float $lat, float $lng): Point
    {
        return Point::makeGeodetic(round($lat, 3), round($lng, 3));
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'kind' => $this->kind,
            'lat' => $this->location->getLatitude(),
            'lng' => $this->location->getLongitude(),
            'area' => $this->area_name,
            'radius_km' => $this->radius_km,
            'is_default' => $this->is_default,
            'is_auto' => $this->is_auto,
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
