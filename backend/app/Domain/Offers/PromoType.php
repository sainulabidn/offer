<?php

namespace App\Domain\Offers;

use Filament\Support\Contracts\HasLabel;

enum PromoType: string implements HasLabel
{
    case Price = 'price';
    case WasNow = 'was_now';
    case Multibuy = 'multibuy';
    case Bogo = 'bogo';
    case PercentOff = 'percent_off';
    case CardPrice = 'card_price';
    case Bundle = 'bundle';
    case Gift = 'gift';
    case Coupon = 'coupon';

    public function getLabel(): string
    {
        return match ($this) {
            self::WasNow => 'Was / now',
            self::Multibuy => 'Multi-buy (n for X)',
            self::Bogo => 'Buy n get m free',
            self::PercentOff => 'Percent off',
            self::CardPrice => 'Loyalty card price',
            default => ucfirst($this->value),
        };
    }
}
