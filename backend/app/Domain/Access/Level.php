<?php

namespace App\Domain\Access;

/**
 * How far a permission reaches. Each level includes the ones before it:
 * own = records the person created; assigned = also anything in their stores; all = everything.
 */
enum Level: string
{
    case Own = 'own';
    case Assigned = 'assigned';
    case All = 'all';

    public function rank(): int
    {
        return match ($this) {
            self::Own => 1,
            self::Assigned => 2,
            self::All => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Own',
            self::Assigned => 'Their stores',
            self::All => 'All',
        };
    }
}
