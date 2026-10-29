<?php

namespace App\Policies;

use App\Domain\Access\Access;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserPolicy extends AccessPolicy
{
    protected string $section = 'users';

    /** Only super admins manage super admins. */
    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && ($record->is($user) || ! $record->hasRole('super_admin') || Access::isSuperAdmin($user));
    }

    /** Nobody deletes their own account here, and only super admins delete super admins. */
    public function delete(User $user, Model $record): bool
    {
        return parent::delete($user, $record) && ! $record->is($user) && (! $record->hasRole('super_admin') || Access::isSuperAdmin($user));
    }
}
