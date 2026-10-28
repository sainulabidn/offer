<?php

namespace App\Domain\Catalog;

use App\Domain\Retail\Chain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Brand extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_private_label' => 'boolean',
            'synonyms' => 'array',
        ];
    }

    /** For private labels: the chain that owns the brand. */
    public function ownerChain(): BelongsTo
    {
        return $this->belongsTo(Chain::class, 'owner_chain_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
