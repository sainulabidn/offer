<?php

namespace App\Domain\Retail;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use App\Domain\Brochures\Brochure;
use App\Domain\Geo\Region;
use App\Domain\Search\ReindexBrochures;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** One physical store. */
class Branch extends Model
{
    use Audited, RecordsCreator;
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name', 'address'];

    /** @var list<int> read before a delete, because the links to brochures go with the row */
    private array $formerBrochureIds = [];

    protected function casts(): array
    {
        return [
            'location' => Point::class,
            'services' => 'array',
            'is_affiliate' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Offers copy their branches' coordinates into Typesense, so these changes reindex them. */
    protected static function booted(): void
    {
        static::saved(function (Branch $branch) {
            if ($branch->wasChanged(['location', 'is_active'])) {
                ReindexBrochures::dispatch($branch->brochures()->pluck('brochures.id')->all());
            }
        });
        static::deleting(fn (Branch $branch) => $branch->formerBrochureIds = $branch->brochures()->pluck('brochures.id')->all());
        static::deleted(fn (Branch $branch) => ReindexBrochures::dispatch($branch->formerBrochureIds));
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

    public function brochures(): BelongsToMany
    {
        return $this->belongsToMany(Brochure::class, 'brochure_branch');
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
