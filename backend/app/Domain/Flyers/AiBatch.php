<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\BrochurePage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Flyer pages sent together through a provider's batch API (FlyerBatches). */
class AiBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['ended_at' => 'datetime'];
    }

    public function pages(): HasMany
    {
        return $this->hasMany(BrochurePage::class);
    }
}
