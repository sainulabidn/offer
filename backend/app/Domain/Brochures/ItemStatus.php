<?php

namespace App\Domain\Brochures;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** A flyer item on its way to becoming an offer (brochure_items.status). */
enum ItemStatus: string implements HasColor, HasLabel
{
    /** Needs a person: no product found, or something to check. */
    case New = 'new';
    /** Product found and nothing to check; waiting to be accepted. */
    case Matched = 'matched';
    /** Accepted: a draft offer exists. */
    case Accepted = 'accepted';
    case Ignored = 'ignored';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Needs a look',
            default => ucfirst($this->value),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Matched => 'info',
            self::Accepted => 'success',
            self::Ignored => 'gray',
        };
    }
}
