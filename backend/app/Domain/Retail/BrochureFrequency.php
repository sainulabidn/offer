<?php

namespace App\Domain\Retail;

use Filament\Support\Contracts\HasLabel;

/** How often a store publishes new offers; a regular store with no live brochure is overdue. */
enum BrochureFrequency: string implements HasLabel
{
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';
    case Irregular = 'irregular';

    public function getLabel(): string
    {
        return match ($this) {
            self::Weekly => 'Every week',
            self::Fortnightly => 'Every 2 weeks',
            self::Monthly => 'Every month',
            self::Irregular => 'Now and then',
        };
    }

    public function isRegular(): bool
    {
        return $this !== self::Irregular;
    }

    /** @return list<string> */
    public static function regular(): array
    {
        return array_column(array_filter(self::cases(), fn (self $case) => $case->isRegular()), 'value');
    }
}
