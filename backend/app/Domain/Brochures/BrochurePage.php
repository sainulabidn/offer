<?php

namespace App\Domain\Brochures;

use App\Domain\Offers\Offer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One page of a flyer: its render (image_path, on the private disk), the PDF's own text when it has any
 * (text_layer: {text}), and where it is in the AI pipeline (ai_status, ai_output).
 */
class BrochurePage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'text_layer' => 'array',
            'ai_status' => PageStatus::class,
            'ai_output' => 'array',
            'extracted_at' => 'datetime',
        ];
    }

    /** The PDF's own text, when the page has selectable text (most flyers are pictures and don't). */
    public function text(): ?string
    {
        return $this->text_layer['text'] ?? null;
    }

    public function brochure(): BelongsTo
    {
        return $this->belongsTo(Brochure::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BrochureItem::class)->orderBy('position');
    }

    public function reusedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reused_from_page_id');
    }
}
