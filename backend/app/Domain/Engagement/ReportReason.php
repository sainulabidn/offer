<?php

namespace App\Domain\Engagement;

use Filament\Support\Contracts\HasLabel;

enum ReportReason: string implements HasLabel
{
    case WrongPrice = 'wrong_price';
    case Expired = 'expired';
    case WrongProduct = 'wrong_product';
    case WrongStore = 'wrong_store';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::WrongPrice => 'Wrong price',
            self::Expired => 'Expired / not in store',
            self::WrongProduct => 'Wrong product or image',
            self::WrongStore => 'Wrong store',
            self::Other => 'Other',
        };
    }
}
