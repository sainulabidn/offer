<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One store an admin user works on: a whole retail group, a chain, or a single branch. */
class UserStoreAccess extends Model
{
    protected $table = 'user_store_access';

    protected $guarded = [];

    public const array TYPES = ['retail_group' => 'Retail group', 'chain' => 'Chain', 'branch' => 'Branch'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{retail_group: list<int>, chain: list<int>, branch: list<int>} the user's stores by type */
    public static function idsFor(User $user): array
    {
        $rows = $user->storeAccess()->get(['scope_type', 'scope_id']);

        return array_map(
            fn (string $type) => $rows->where('scope_type', $type)->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all(),
            array_combine(array_keys(self::TYPES), array_keys(self::TYPES)),
        );
    }

    /** Replace the user's stores with exactly these. @param array<string, list<int|string>> $ids by type */
    public static function sync(User $user, array $ids): void
    {
        $user->storeAccess()->delete();
        foreach (array_keys(self::TYPES) as $type) {
            foreach (array_unique($ids[$type] ?? []) as $id) {
                $user->storeAccess()->create(['scope_type' => $type, 'scope_id' => (int) $id]);
            }
        }
        Access::forget($user);
    }
}
