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

    /** The unit for a code or any brochure spelling listed in units.aliases (kgs, ltr, pcs, 's…). */
    public static function fromAlias(string $value): ?self
    {
        $needle = mb_strtolower(trim($value));
        if ($needle === '') {
            return null;
        }

        // A handful of rows, read once per request or job.
        return once(fn () => self::query()->get())->first(fn (Unit $unit) => $unit->code === $needle || in_array($needle, $unit->aliases ?? [], true));
    }

    protected function casts(): array
    {
        return [
            'dimension' => UnitDimension::class,
            'factor_to_base' => 'decimal:6',
            'aliases' => 'array',
        ];
    }
}
