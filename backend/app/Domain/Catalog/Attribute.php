<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** Registry entry such as origin, fat %, organic. data_type: option | multi_option | boolean | number | text | country. */
class Attribute extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'is_searchable' => 'boolean',
        ];
    }

    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class)->orderBy('sort');
    }
}
