<?php

namespace App\Domain\Brochures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One rule of where a brochure applies: scope_type chain | region | branch | group, plus that record's ID. */
class BrochureScope extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function brochure(): BelongsTo
    {
        return $this->belongsTo(Brochure::class);
    }
}
