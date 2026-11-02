<?php

namespace App\Domain\Retail;

use App\Domain\Access\Access;
use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Geo\Country;
use App\Domain\Geo\Region;
use App\Domain\Search\ReindexBrochures;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
            'brochure_frequency' => BrochureFrequency::class,
            'content_permission' => ContentPermission::class,
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /** Offers copy these into Typesense. */
    protected static function booted(): void
    {
        // Ticking "verified" records who checked and when; unticking clears it.
        static::saving(function (Chain $chain) {
            if ($chain->isDirty('is_verified')) {
                $chain->verified_at = $chain->is_verified ? now() : null;
                $chain->verified_by = $chain->is_verified ? Access::user()?->id : null;
            }
        });

        static::saved(function (Chain $chain) {
            if ($chain->wasChanged(['grade', 'is_active', 'name', 'slug'])) {
                ReindexBrochures::dispatch($chain->brochures()->pluck('id')->all());
            }
        });
    }

    /** Adds brochures_until: the last day covered by a published or scheduled brochure. */
    public function scopeWithBrochuresUntil(Builder $query): void
    {
        $query->withMax(['brochures as brochures_until' => fn (Builder $q) => $q->whereIn('status', [BrochureStatus::Published, BrochureStatus::Scheduled])], 'valid_to');
    }

    /** A store that publishes regularly but has no brochure for today. Needs withBrochuresUntil(). */
    public function brochureOverdue(): bool
    {
        $today = now(config('app.market_timezone'))->toDateString();

        return (bool) $this->brochure_frequency?->isRegular() && (! $this->brochures_until || substr($this->brochures_until, 0, 10) < $today);
    }

    /**
     * UAE coverage: the emirates with an active branch, worked out from the branches, so it can't go stale.
     * Needs branches.region.parent loaded.
     *
     * @return list<string>
     */
    public function emirates(): array
    {
        return $this->branches
            ->where('is_active', true)
            ->map(fn (Branch $branch) => $branch->region?->type === 'emirate' ? $branch->region : $branch->region?->parent)
            ->filter()
            ->unique('id')
            ->sortBy('sort')
            ->map(fn (Region $emirate) => $emirate->name)
            ->values()
            ->all();
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
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
