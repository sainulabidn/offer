<?php

namespace App\Domain\Flyers\Sources;

use App\Domain\Brochures\Brochure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One check of a source and what came of it: new, unchanged, duplicate, gone (a dead link on the page), no_links, blocked or failed. */
class BrochureFetch extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BrochureSource::class, 'brochure_source_id');
    }

    public function brochure(): BelongsTo
    {
        return $this->belongsTo(Brochure::class);
    }
}
