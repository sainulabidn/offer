<?php

namespace App\Domain\Retail;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use App\Domain\Geo\Region;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** One physical store. */
class Branch extends Model
{
    use Audited, RecordsCreator;
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name', 'address'];

    protected function casts(): array
    {
        return [
            'location' => Point::class,
            'services' => 'array',
            'is_affiliate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function operatorGroup(): BelongsTo
    {
        return $this->belongsTo(RetailGroup::class, 'operator_group_id');
    }

    public function hours(): HasMany
    {
        return $this->hasMany(BranchHour::class)->orderBy('weekday')->orderBy('opens_at');
    }

    public function hourExceptions(): HasMany
    {
        return $this->hasMany(BranchHourException::class);
    }
}
