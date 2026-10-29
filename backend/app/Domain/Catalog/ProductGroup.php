<?php

namespace App\Domain\Catalog;

use App\Domain\Access\Audited;
use App\Domain\Access\NeedsReview;
use App\Domain\Access\RecordsCreator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** Generic comparable product ("Basmati Rice"); prices compare per comparison_quantity × comparison unit. */
class ProductGroup extends Model
{
    use Audited, NeedsReview, RecordsCreator;
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'comparison_quantity' => 'decimal:3',
            'synonyms' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function comparisonUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'comparison_unit_code');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
