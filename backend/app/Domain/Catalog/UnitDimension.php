<?php

namespace App\Domain\Catalog;

use Filament\Support\Contracts\HasLabel;

/** Base units: mass → g, volume → ml, count → pc, length → m, sheets → sheet, washes → wash. */
enum UnitDimension: string implements HasLabel
{
    case Mass = 'mass';
    case Volume = 'volume';
    case Count = 'count';
    case Length = 'length';
    case Sheets = 'sheets';
    case Washes = 'washes';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
