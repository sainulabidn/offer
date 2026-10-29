<?php

namespace App\Domain\Access;

use App\Domain\Offers\Offer;
use App\Domain\Retail\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use WeakMap;

/**
 * Admin permissions with reach: "can this person edit branches, and which ones?"
 * Policies ask allows(); admin lists and pickers are filtered with scope().
 * Super admins can do everything; disabled users nothing.
 */
final class Access
{
    /** @var WeakMap<User, array<string, true>> permission names per user object (one request) */
    private static ?WeakMap $names = null;

    /** The signed-in admin user, if any (the admin panel signs in separately from shoppers). */
    public static function user(): ?User
    {
        $user = Auth::guard('admin')->user();

        return $user instanceof User ? $user : null;
    }

    public static function isSuperAdmin(?User $user): bool
    {
        return $user !== null && $user->disabled_at === null && $user->hasRole('super_admin');
    }

    /** The person's widest level for one action, or null when they can't do it at all. */
    public static function level(?User $user, string $section, string $action): ?Level
    {
        if ($user === null || $user->disabled_at !== null) {
            return null;
        }
        if (self::isSuperAdmin($user)) {
            return Level::All;
        }

        self::$names ??= new WeakMap;
        $names = self::$names[$user] ??= $user->loadMissing(['roles.permissions', 'permissions'])
            ->getAllPermissions()->pluck('name')->flip()->map(fn () => true)->all();

        foreach ([Level::All, Level::Assigned, Level::Own] as $level) {
            if (isset($names["{$section}.{$action}.{$level->value}"])) {
                // "Their stores" for someone who works on every store is everything.
                return $level === Level::Assigned && $user->all_stores ? Level::All : $level;
            }
        }

        return null;
    }

    public static function forget(User $user): void
    {
        unset(self::$names[$user]);
        StoreScope::forget($user);
    }

    /** May the person do this at all (no record), or to this record? */
    public static function allows(?User $user, string $section, string $action, ?Model $record = null): bool
    {
        $level = self::level($user, $section, $action);
        if ($level === null) {
            return false;
        }
        if ($record === null || $level === Level::All) {
            return $record === null || self::visibleForReview($user, $section, $record);
        }

        return self::scope($record->newQuery()->whereKey($record->getKey()), $user, $section, $action)->exists();
    }

    /** Limit a query to the records the person may act on. */
    public static function scope(Builder $query, ?User $user, string $section, string $action = 'view'): Builder
    {
        $level = self::level($user, $section, $action);
        if ($level === null) {
            return $query->whereRaw('false');
        }

        $table = $query->getModel()->getTable();
        $owner = AccessRules::section($section)['owner'];

        if ($level !== Level::All) {
            $query->where(function (Builder $query) use ($user, $section, $action, $level, $owner, $table) {
                $query->whereRaw('false');
                if ($owner) {
                    $query->orWhere("{$table}.{$owner}", $user->id);
                }
                if ($level === Level::Assigned) {
                    $query->orWhere(fn (Builder $query) => self::inStores($query, $section, $action, StoreScope::for($user), $table));
                }
            });
        }

        // Catalog items waiting for review are only for reviewers and the person who added them.
        if (isset(AccessRules::section($section)['actions']['approve']) && self::level($user, $section, 'approve') === null) {
            $query->where(fn (Builder $query) => $query
                ->where("{$table}.review_status", ReviewStatus::Approved->value)
                ->orWhere("{$table}.created_by", $user->id));
        }

        return $query;
    }

    private static function visibleForReview(?User $user, string $section, Model $record): bool
    {
        if (! isset(AccessRules::section($section)['actions']['approve']) || self::level($user, $section, 'approve') !== null) {
            return true;
        }

        return $record->getAttribute('review_status') === ReviewStatus::Approved || $record->getAttribute('created_by') === $user?->id;
    }

    /** Records that belong to the person's stores. */
    private static function inStores(Builder $query, string $section, string $action, StoreScope $stores, string $table): void
    {
        match ($section) {
            'retail_groups' => $query->whereIn("{$table}.id", $stores->groupIds),
            // Branch-only users see (not edit) their branches' chains, e.g. to add a brochure for their branch.
            'chains' => $query->whereIn("{$table}.id", $action === 'view' ? $stores->chainsToPick : $stores->chainIds),
            'branches' => $query->whereIn("{$table}.id", $stores->branchIds),
            // A brochure of their chain, or one that covers one of their branches.
            'brochures' => $query->whereIn("{$table}.chain_id", $stores->chainIds)
                ->orWhereHas('branches', fn (Builder $q) => $q->whereIn('branches.id', $stores->branchIds)),
            'reports' => $query
                ->whereHasMorph('reportable', [Offer::class], fn (Builder $q) => $q->whereIn('chain_id', $stores->chainIds))
                ->orWhereHasMorph('reportable', [Branch::class], fn (Builder $q) => $q->whereIn('id', $stores->branchIds)),
            default => $query->whereRaw('false'), // the shared catalog has no stores: "own" or "all"
        };
    }
}
