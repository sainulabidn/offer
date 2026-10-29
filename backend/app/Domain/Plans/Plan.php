<?php

namespace App\Domain\Plans;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/** A shopping plan synced from the browser; shared plans can be edited by their members too. */
class Plan extends Model
{
    use HasUuids;

    /** A plan only holds a shopping list; keep it bounded. */
    public const int MAX_ITEMS = 200;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'settings' => 'array',
            'client_updated_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'plan_members');
    }

    public function canEdit(User $user): bool
    {
        return $this->user_id === $user->id || $this->members()->whereKey($user->id)->exists();
    }

    public function shareToken(): string
    {
        if (! $this->share_token) {
            $this->forceFill(['share_token' => Str::random(32)])->save();
        }

        return $this->share_token;
    }

    public function toApi(User $viewer): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'items' => $this->items ?? [],
            'settings' => (object) ($this->settings ?? []),
            'updated_at' => $this->client_updated_at->toIso8601String(),
            'is_owner' => $this->user_id === $viewer->id,
            'shared' => $this->share_token !== null,
            'members' => $this->members()->count(),
        ];
    }
}
