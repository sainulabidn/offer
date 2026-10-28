<?php

namespace App\Domain\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class LoyaltyProgram extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name'];

    public function retailGroup(): BelongsTo
    {
        return $this->belongsTo(RetailGroup::class);
    }
}
