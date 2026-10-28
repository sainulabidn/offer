<?php

namespace Database\Seeders;

use App\Domain\Catalog\Attribute;
use App\Domain\Catalog\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Starter category tree and attribute registry. Admins extend both in Filament. */
class CatalogStructureSeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'Fresh Food' => ['Fruits & Vegetables', 'Meat & Poultry', 'Fish & Seafood', 'Dairy & Eggs', 'Bakery'],
            'Pantry' => ['Rice & Grains', 'Oil & Ghee', 'Flour & Baking', 'Pulses & Lentils', 'Spices', 'Tea & Coffee', 'Canned Food', 'Breakfast & Cereals', 'Snacks & Sweets'],
            'Frozen Food' => [],
            'Beverages' => ['Water', 'Soft Drinks', 'Juices'],
            'Household' => ['Laundry', 'Cleaning', 'Paper & Tissues'],
            'Personal Care' => [],
            'Baby' => [],
        ];

        $sort = 0;
        foreach ($tree as $parentName => $children) {
            $parent = Category::updateOrCreate(['slug' => Str::slug($parentName)], ['name' => ['en' => $parentName], 'sort' => $sort++]);

            foreach (array_values($children) as $childSort => $childName) {
                Category::updateOrCreate(['slug' => Str::slug($childName)], ['name' => ['en' => $childName], 'parent_id' => $parent->id, 'sort' => $childSort]);
            }
        }

        // code => [English name, data type, options (code => English label)]
        $attributes = [
            'origin' => ['Origin', 'country', []],
            'fat_content' => ['Fat content', 'option', ['full' => 'Full fat', 'low' => 'Low fat', 'skimmed' => 'Skimmed']],
            'packaging' => ['Packaging', 'option', ['bag' => 'Bag', 'box' => 'Box', 'bottle' => 'Bottle', 'can' => 'Can', 'jar' => 'Jar', 'pouch' => 'Pouch', 'tray' => 'Tray']],
            'organic' => ['Organic', 'boolean', []],
            'gluten_free' => ['Gluten-free', 'boolean', []],
            'sugar_free' => ['Sugar-free', 'boolean', []],
            'flavour' => ['Flavour', 'text', []],
            'type' => ['Type', 'text', []],
        ];

        $sort = 0;
        foreach ($attributes as $code => [$name, $type, $options]) {
            $attribute = Attribute::updateOrCreate(['code' => $code], ['name' => ['en' => $name], 'data_type' => $type, 'sort' => $sort++]);

            $optionSort = 0;
            foreach ($options as $optionCode => $label) {
                $attribute->options()->updateOrCreate(['code' => $optionCode], ['name' => ['en' => $label], 'sort' => $optionSort++]);
            }
        }
    }
}
