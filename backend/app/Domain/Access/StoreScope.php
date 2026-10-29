<?php

namespace App\Domain\Access;

use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Models\User;
use WeakMap;

/**
 * The stores one admin user works on, expanded downwards: a retail group brings all its chains and
 * branches, a chain all its branches. Branch-only users also get their branches' chains in `chainsToPick`
 * (to choose the chain when adding a brochure for their branch), but not the rest of those chains.
 */
final class StoreScope
{
    /** @var WeakMap<User, self> once per user object, i.e. once per request */
    private static ?WeakMap $cache = null;

    /**
     * @param  list<int>  $groupIds
     * @param  list<int>  $chainIds
     * @param  list<int>  $branchIds
     * @param  list<int>  $chainsToPick
     */
    public function __construct(
        public readonly array $groupIds,
        public readonly array $chainIds,
        public readonly array $branchIds,
        public readonly array $chainsToPick,
    ) {}

    public static function for(User $user): self
    {
        self::$cache ??= new WeakMap;

        return self::$cache[$user] ??= self::resolve($user);
    }

    public static function forget(User $user): void
    {
        unset(self::$cache[$user]);
    }

    private static function resolve(User $user): self
    {
        $rows = $user->storeAccess()->get(['scope_type', 'scope_id'])->groupBy('scope_type')->map->pluck('scope_id');
        $groups = ($rows['retail_group'] ?? collect())->map(fn ($id) => (int) $id);
        $chains = Chain::whereIn('retail_group_id', $groups)->pluck('id')->merge($rows['chain'] ?? [])->map(fn ($id) => (int) $id)->unique();
        $branches = Branch::whereIn('chain_id', $chains)->pluck('id')->merge($rows['branch'] ?? [])->map(fn ($id) => (int) $id)->unique();
        $pick = Branch::whereIn('id', $rows['branch'] ?? [])->pluck('chain_id')->merge($chains)->map(fn ($id) => (int) $id)->unique();

        return new self($groups->values()->all(), $chains->values()->all(), $branches->values()->all(), $pick->values()->all());
    }
}
