<?php

namespace App\Domain\Brochures;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use App\Domain\Flyers\Sources\BrochureSource;
use App\Domain\Offers\Offer;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/** A weekly/daily brochure (PDF, images, or "digital" for web-only offers). `validity` is a generated daterange. */
class Brochure extends Model
{
    use Audited, RecordsCreator;
    use HasTranslations;

    /**
     * Not a real brochure: holds a store's offers added on the Offers page or by CSV, one per store and
     * set of branches, so they reach the website the same way brochure offers do. See DirectOffers.
     */
    public const string TYPE_DIRECT = 'direct';

    protected $guarded = ['validity'];

    public array $translatable = ['title'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'status' => BrochureStatus::class,
            'published_at' => 'datetime',
            'detected' => 'array',
        ];
    }

    /** Uploaded as a flyer for the AI to read (Flyers\StartFlyerImport). */
    public function isFlyerImport(): bool
    {
        return $this->ai_mode !== null;
    }

    /** Offers the AI read off the pages, before and after review. */
    public function items(): HasMany
    {
        return $this->hasMany(BrochureItem::class);
    }

    public function aiRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ai_requested_by');
    }

    /** Fetched automatically from this source (Flyers\Sources). */
    public function brochureSource(): BelongsTo
    {
        return $this->belongsTo(BrochureSource::class);
    }

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(BrochurePage::class)->orderBy('page_no');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** Where the brochure applies; empty means every branch of its chain. */
    public function scopes(): HasMany
    {
        return $this->hasMany(BrochureScope::class);
    }

    /** Branch scope resolved at publish time. */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'brochure_branch');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
