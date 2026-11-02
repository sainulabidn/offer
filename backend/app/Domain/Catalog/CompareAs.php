<?php

namespace App\Domain\Catalog;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * What one card on the home page stands for. Shoppers who want tomatoes take any origin, so the group
 * competes as one item; shoppers who want Ferrero Rocher want that product, in whatever box is cheapest.
 */
enum CompareAs: string implements HasDescription, HasLabel
{
    case Group = 'group';
    case Product = 'product';

    public function getLabel(): string
    {
        return match ($this) {
            self::Group => 'The whole group',
            self::Product => 'Each product',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Group => 'Any origin, brand or variety will do: one card shows the cheapest per unit, e.g. tomatoes.',
            self::Product => 'Shoppers want a particular product: each gets its own card with its cheapest size per unit, e.g. Ferrero Rocher.',
        };
    }

    /** The search field value that groups offers into one card. */
    public function key(int $groupId, int $productId): string
    {
        return $this === self::Group ? "g{$groupId}" : "p{$productId}";
    }
}
