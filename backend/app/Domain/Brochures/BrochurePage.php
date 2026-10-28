<?php

namespace App\Domain\Brochures;

use App\Domain\Offers\Offer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BrochurePage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['text_layer' => 'array'];
    }

    public function brochure(): BelongsTo
    {
        return $this->belongsTo(Brochure::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}
