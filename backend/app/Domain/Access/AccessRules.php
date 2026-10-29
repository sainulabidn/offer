<?php

namespace App\Domain\Access;

use App\Domain\Brochures\Brochure;
use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Engagement\Report;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Retail\RetailGroup;
use App\Domain\Search\Synonym;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Every admin section, the actions on it and the levels each action can have. The role editor builds its
 * grid from this list, and permissions are named "{section}.{action}.{level}", e.g. "branches.edit.assigned".
 * An action that only has "all" is a plain yes/no.
 */
final class AccessRules
{
    public const array ACTIONS = [
        'view' => 'See',
        'create' => 'Add',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'publish' => 'Publish',
        'approve' => 'Approve',
        'resolve' => 'Resolve',
    ];

    /** @return array<string, array{label: string, group: string, model: class-string, owner: ?string, actions: array<string, list<Level>>}> */
    public static function sections(): array
    {
        $stores = [Level::Own, Level::Assigned, Level::All];
        $catalog = [Level::Own, Level::All];
        $yes = [Level::All];

        $storeSection = fn (string $label, string $model) => [
            'label' => $label, 'group' => 'Stores', 'model' => $model, 'owner' => 'created_by',
            'actions' => ['view' => $stores, 'create' => $yes, 'edit' => $stores, 'delete' => $stores],
        ];
        $catalogSection = fn (string $label, string $model) => [
            'label' => $label, 'group' => 'Catalog', 'model' => $model, 'owner' => 'created_by',
            'actions' => ['view' => $catalog, 'create' => $yes, 'edit' => $catalog, 'delete' => $catalog, 'approve' => $yes],
        ];
        $yesNo = fn (string $label, string $group, string $model) => [
            'label' => $label, 'group' => $group, 'model' => $model, 'owner' => null,
            'actions' => ['view' => $yes, 'create' => $yes, 'edit' => $yes, 'delete' => $yes],
        ];

        return [
            'retail_groups' => $storeSection('Retail groups', RetailGroup::class),
            'chains' => $storeSection('Chains (stores)', Chain::class),
            'branches' => $storeSection('Branches', Branch::class),
            'brochures' => [
                'label' => 'Brochures and offers', 'group' => 'Offers', 'model' => Brochure::class, 'owner' => 'created_by',
                'actions' => ['view' => $stores, 'create' => $yes, 'edit' => $stores, 'delete' => $stores, 'publish' => $stores],
            ],
            'reports' => [
                'label' => 'Shopper reports', 'group' => 'Offers', 'model' => Report::class, 'owner' => null,
                'actions' => ['view' => [Level::Assigned, Level::All], 'resolve' => [Level::Assigned, Level::All]],
            ],
            'categories' => $catalogSection('Categories', Category::class),
            'brands' => $catalogSection('Brands', Brand::class),
            'product_groups' => $catalogSection('Product groups', ProductGroup::class),
            'products' => $catalogSection('Products', Product::class),
            'synonyms' => $yesNo('Search synonyms', 'Catalog', Synonym::class),
            'users' => $yesNo('Users', 'Access', User::class),
            'roles' => $yesNo('Roles and permissions', 'Access', Role::class),
            'activity' => [
                'label' => 'Activity log', 'group' => 'Access', 'model' => Activity::class, 'owner' => null,
                'actions' => ['view' => $yes],
            ],
        ];
    }

    public static function section(string $key): array
    {
        return self::sections()[$key] ?? throw new \InvalidArgumentException("Unknown access section [{$key}].");
    }

    /** The section a model belongs to, e.g. Product::class → "products". */
    public static function sectionFor(string $modelClass): ?string
    {
        foreach (self::sections() as $key => $section) {
            if ($section['model'] === $modelClass) {
                return $key;
            }
        }

        return null;
    }

    /** @return list<string> every permission name, for seeding */
    public static function permissionNames(): array
    {
        $names = [];
        foreach (self::sections() as $key => $section) {
            foreach ($section['actions'] as $action => $levels) {
                foreach ($levels as $level) {
                    $names[] = "{$key}.{$action}.{$level->value}";
                }
            }
        }

        return $names;
    }
}
