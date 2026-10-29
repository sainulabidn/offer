<?php

namespace App\Domain\Catalog;

use App\Domain\Retail\Chain;
use App\Domain\Search\Synonyms;
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

    protected static function booted(): void
    {
        // Other spellings ("IG") are search synonyms of the brand name.
        static::saved(function (Brand $brand) {
            if ($brand->synonyms && ($brand->wasRecentlyCreated || $brand->wasChanged(['synonyms', 'name']))) {
                app(Synonyms::class)->syncQuietly();
            }
        });
        static::deleted(fn (Brand $brand) => $brand->synonyms && app(Synonyms::class)->syncQuietly());
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
