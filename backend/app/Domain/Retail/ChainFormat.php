<?php

namespace App\Domain\Retail;

use Filament\Support\Contracts\HasLabel;

enum ChainFormat: string implements HasLabel
{
    case Hypermarket = 'hypermarket';
    case Supermarket = 'supermarket';
    case Express = 'express';
    case Convenience = 'convenience';
    case Pharmacy = 'pharmacy';
    case Electronics = 'electronics';
    case Online = 'online';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
