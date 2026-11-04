<?php

namespace App\Domain\Brochures;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Where a flyer page is in the AI pipeline (brochure_pages.ai_status). */
enum PageStatus: string implements HasColor, HasLabel
{
    /** Uploaded, not rendered yet. */
    case Pending = 'pending';
    /** No prices on it (covers, terms): no AI call. */
    case Skipped = 'skipped';
    /** The same page was read before; its answer is reused for free. */
    case Reused = 'reused';
    /** Waiting for the AI. */
    case Queued = 'queued';
    case Extracting = 'extracting';
    /** Economy: waiting in (or for) a half-price batch, answered within hours. */
    case Batched = 'batched';
    case Done = 'done';
    case Failed = 'failed';
    /** Over the monthly AI budget: waits until it is raised or the month turns. */
    case Held = 'held';

    /** Still on its way: the flyer isn't finished while a page is in one of these. */
    public const array OPEN = [self::Pending, self::Queued, self::Extracting, self::Batched, self::Held];

    public function isOpen(): bool
    {
        return in_array($this, self::OPEN, true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Held => 'On hold (budget)',
            self::Batched => 'In a batch',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Done, self::Reused => 'success',
            self::Queued, self::Extracting, self::Pending, self::Batched => 'info',
            self::Held => 'warning',
            self::Failed => 'danger',
            default => 'gray',
        };
    }
}
