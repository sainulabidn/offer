<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** A variation dimension of one product: Size, Type, Flavour, Origin… */
class ProductOption extends Model
{
    use HasTranslations;

    public $timestamps = false;

    protected $guarded = [];

    public array $translatable = ['name'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Set when the option maps to a registry attribute (e.g. origin), so its values become search facets. */
    public function attributeDefinition(): BelongsTo
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class)->orderBy('sort');
    }
}
