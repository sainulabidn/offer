<?php

namespace Database\Seeders;

use App\Domain\Access\AccessRules;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every admin permission (App\Domain\Access\AccessRules) and the built-in roles with sensible defaults.
 * Safe to re-run: a role keeps the permissions staff gave it in the admin; defaults only fill empty roles.
 * super_admin needs none: it can do everything.
 */
class AccessSeeder extends Seeder
{
    private const array CATALOG = ['categories', 'brands', 'product_groups', 'products'];

    public function run(): void
    {
        foreach (AccessRules::permissionNames() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate('super_admin', 'web');
        foreach (self::defaults() as $roleName => $grid) {
            $role = Role::findOrCreate($roleName, 'web');
            if ($role->permissions()->doesntExist()) {
                $role->syncPermissions(self::names($grid));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @param  array<string, array<string, string>>  $grid  section => action => level */
    public static function names(array $grid): array
    {
        return collect($grid)->flatMap(fn (array $actions, string $section) => collect($actions)
            ->map(fn (string $level, string $action) => "{$section}.{$action}.{$level}")->values())->values()->all();
    }

    /** @return array<string, array<string, array<string, string>>> */
    public static function defaults(): array
    {
        $everything = fn (array $actions) => array_fill_keys($actions, 'all');
        $catalog = fn (array $actions) => array_fill_keys(self::CATALOG, $actions);

        return [
            // Office staff who build the catalog and enter brochures for every store.
            'data_operator' => [
                'retail_groups' => $everything(['view', 'create', 'edit']),
                'chains' => $everything(['view', 'create', 'edit']),
                'branches' => $everything(['view', 'create', 'edit', 'delete']),
                'brochures' => $everything(['view', 'create', 'edit', 'delete', 'publish']),
                'reports' => $everything(['view', 'resolve']),
                'synonyms' => $everything(['view', 'create', 'edit', 'delete']),
                ...$catalog($everything(['view', 'create', 'edit', 'delete', 'approve'])),
            ],
            // Checks what others entered: approves catalog items, publishes brochures, handles reports.
            'reviewer' => [
                'retail_groups' => ['view' => 'all'],
                'chains' => ['view' => 'all'],
                'branches' => ['view' => 'all'],
                'brochures' => $everything(['view', 'publish']),
                'reports' => $everything(['view', 'resolve']),
                'activity' => ['view' => 'all'],
                ...$catalog($everything(['view', 'approve'])),
            ],
            // A retailer (e.g. Nesto): manages its own group, chains, branches and brochures. New catalog
            // items wait for review; publishing is given per retailer in the admin ("brochures → publish").
            'store_owner' => [
                'retail_groups' => ['view' => 'assigned', 'create' => 'all', 'edit' => 'assigned'],
                'chains' => ['view' => 'assigned', 'create' => 'all', 'edit' => 'assigned'],
                'branches' => ['view' => 'assigned', 'create' => 'all', 'edit' => 'assigned', 'delete' => 'assigned'],
                'brochures' => ['view' => 'assigned', 'create' => 'all', 'edit' => 'assigned', 'delete' => 'assigned'],
                'reports' => ['view' => 'assigned'],
                ...$catalog(['view' => 'all', 'create' => 'all', 'edit' => 'own', 'delete' => 'own']),
            ],
            // A retailer's employee: keeps branch details current and enters brochures.
            'store_staff' => [
                'chains' => ['view' => 'assigned'],
                'branches' => ['view' => 'assigned', 'edit' => 'assigned'],
                'brochures' => ['view' => 'assigned', 'create' => 'all', 'edit' => 'own'],
                ...$catalog(['view' => 'all']),
                'products' => ['view' => 'all', 'create' => 'all', 'edit' => 'own'],
            ],
        ];
    }
}
