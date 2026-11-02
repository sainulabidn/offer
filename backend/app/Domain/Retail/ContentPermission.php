<?php

namespace App\Domain\Retail;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Whether the store agreed to us showing its brochures, prices and logo. Staff only. */
enum ContentPermission: string implements HasColor, HasLabel
{
    case Unknown = 'unknown';
    case Requested = 'requested';
    case Granted = 'granted';
    case Refused = 'refused';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unknown => 'Not asked yet',
            self::Requested => 'Asked, waiting',
            self::Granted => 'Agreed',
            self::Refused => 'Refused',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unknown => 'gray',
            self::Requested => 'warning',
            self::Granted => 'success',
            self::Refused => 'danger',
        };
    }
}
