<?php

namespace App\Domain\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/** Ramadan, Eid or holiday hours over a date range (PostgreSQL daterange, no overlaps per branch). */
class BranchHourException extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['label'];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
