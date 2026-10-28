<?php

namespace App\Domain\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** weekday 0 = Sunday … 6 = Saturday; closes_at earlier than opens_at means the shift ends after midnight. */
class BranchHour extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
