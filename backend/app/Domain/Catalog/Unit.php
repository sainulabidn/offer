<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/** Measurement unit; factor_to_base converts to the dimension's base unit (kg → 1000 g). */
class Unit extends Model
{
    use HasTranslations;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    protected $guarded = [];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'dimension' => UnitDimension::class,
            'factor_to_base' => 'decimal:6',
            'aliases' => 'array',
        ];
    }
}
