<?php

namespace App\Filament\Support;

use App\Domain\Access\Access;
use App\Domain\Access\AccessRules;
use Illuminate\Database\Eloquent\Builder;

/**
 * For admin resources: lists, global search and record pages only contain what the signed-in person may
 * see (their own records, their stores' records, or everything; see App\Domain\Access).
 */
trait ScopedByAccess
{
    public static function getEloquentQuery(): Builder
    {
        return Access::scope(parent::getEloquentQuery(), Access::user(), AccessRules::sectionFor(static::getModel()));
    }
}
