<?php

namespace App\Domain\Offers;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OfferStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Published = 'published';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Published => 'success',
            self::Approved => 'info',
            self::Withdrawn => 'danger',
            default => 'gray',
        };
    }
}
