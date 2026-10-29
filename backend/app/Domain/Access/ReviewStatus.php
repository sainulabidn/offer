<?php

namespace App\Domain\Access;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Shared-catalog items added by store owners wait for staff review before the website shows them. */
enum ReviewStatus: string implements HasColor, HasLabel
{
    case Approved = 'approved';
    case Pending = 'pending';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Pending => 'Waiting for review',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Pending => 'warning',
            self::Rejected => 'danger',
        };
    }
}
