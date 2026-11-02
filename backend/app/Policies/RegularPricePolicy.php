<?php

namespace App\Policies;

class RegularPricePolicy extends AccessPolicy
{
    protected string $section = 'shelf_prices';
}
