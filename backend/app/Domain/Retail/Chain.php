<?php

namespace App\Domain\Retail;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use App\Domain\Brochures\Brochure;
use App\Domain\Geo\Country;
use App\Domain\Search\ReindexBrochures;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
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

    /** Offers copy these into Typesense. */
    protected static function booted(): void
    {
        static::saved(function (Chain $chain) {
            if ($chain->wasChanged(['grade', 'is_active', 'name', 'slug'])) {
                ReindexBrochures::dispatch($chain->brochures()->pluck('id')->all());
            }
        });
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk(config('filesystems.media_disk'))->url($this->logo_path) : null;
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
