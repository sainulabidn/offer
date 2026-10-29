<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RolePolicy extends AccessPolicy
{
    protected string $section = 'roles';

    /** Super admin always has every permission, so there is nothing to edit. */
    public function update(User $user, Model $record): bool
    {
        return $record->name !== 'super_admin' && parent::update($user, $record);
    }

    /** Built-in roles and roles still given to someone stay. */
    public function delete(User $user, Model $record): bool
    {
        return ! in_array($record->name, User::STAFF_ROLES, true)
            && $record->users()->doesntExist()
            && parent::delete($user, $record);
    }
}
