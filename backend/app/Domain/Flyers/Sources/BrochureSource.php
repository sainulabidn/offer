<?php

namespace App\Domain\Flyers\Sources;

use App\Domain\Access\Audited;
use App\Domain\Access\RecordsCreator;
use App\Domain\Brochures\Brochure;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ContentPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a store's flyers are fetched from automatically (FetchBrochureSource): a PDF link that stays the
 * same ("pdf_url"), or a web page whose links matching link_pattern are the flyers ("page"). Runs only for
 * stores that gave permission (chains.content_permission granted).
 */
class BrochureSource extends Model
{
    use Audited, RecordsCreator;

    public const string KIND_PDF = 'pdf_url';

    public const string KIND_PAGE = 'page';

    /** One Nesto store on nestooffers.com (NestoOffers). */
    public const string KIND_NESTO = 'nesto';

    /** Links on a page that look like flyer PDFs, when the source sets no pattern. */
    public const string DEFAULT_PATTERN = '\.pdf($|[?#])';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'branch_ids' => 'array',
            'next_check_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_new_at' => 'datetime',
            'failure_alerted_at' => 'datetime',
            'stale_alerted_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** May we fetch this store's flyers at all? */
    public function permitted(): bool
    {
        return $this->chain?->content_permission === ContentPermission::Granted;
    }

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    public function fetches(): HasMany
    {
        return $this->hasMany(BrochureFetch::class)->latest('id');
    }

    public function brochures(): HasMany
    {
        return $this->hasMany(Brochure::class);
    }
}
