<?php

namespace App\Domain\Retail;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use App\Domain\Brochures\Brochure;
use App\Domain\Geo\Country;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** A store banner/format such as "Lulu Hypermarket". */
class Chain extends Model
{
    use Audited, RecordsCreator;
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'format' => ChainFormat::class,
            'is_active' => 'boolean',
        ];
    }

    public function retailGroup(): BelongsTo
    {
        return $this->belongsTo(RetailGroup::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function brochures(): HasMany
    {
        return $this->hasMany(Brochure::class);
    }
}
