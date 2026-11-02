<?php

namespace App\Domain\Shopper;

use Illuminate\Database\Eloquent\Model;

/**
 * Settings a signed-in shopper keeps across devices. Empty radii mean the site defaults. The browser
 * keeps a copy in its cookie, so pages can be drawn without asking the server; this is the master copy.
 */
class ShopperPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $attributes = ['branch_ids' => '[]', 'item_radius_off' => false, 'personalize' => false];

    protected function casts(): array
    {
        return [
            'item_radius_off' => 'boolean',
            'branch_ids' => 'array',
            'personalize' => 'boolean',
            'client_updated_at' => 'datetime',
        ];
    }

    public function toApi(): array
    {
        return [
            'radius_km' => $this->radius_km,
            'item_radius_km' => $this->item_radius_km,
            'item_radius_off' => $this->item_radius_off,
            'branch_ids' => $this->branch_ids ?? [],
            'personalize' => $this->personalize,
            'updated_at' => $this->client_updated_at?->toIso8601String(),
        ];
    }
}
