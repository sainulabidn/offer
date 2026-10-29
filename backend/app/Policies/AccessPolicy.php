<?php

namespace App\Policies;

use App\Domain\Access\Access;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin's permissions for one section (App\Domain\Access\AccessRules), as a Laravel policy:
 * Filament uses it to show menus, buttons and pages; list queries are filtered separately.
 */
abstract class AccessPolicy
{
    /** The section key in AccessRules, e.g. "branches". */
    protected string $section;

    public function viewAny(User $user): bool
    {
        return Access::allows($user, $this->section, 'view');
    }

    public function view(User $user, Model $record): bool
    {
        return Access::allows($user, $this->section, 'view', $record);
    }

    public function create(User $user): bool
    {
        return Access::allows($user, $this->section, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return Access::allows($user, $this->section, 'edit', $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return Access::allows($user, $this->section, 'delete', $record);
    }

    public function deleteAny(User $user): bool
    {
        return Access::allows($user, $this->section, 'delete');
    }
}
