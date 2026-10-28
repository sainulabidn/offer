<?php

namespace Database\Seeders;

use App\Domain\Catalog\Brand;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductGroup;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Geo\Country;
use App\Domain\Geo\Region;
use App\Domain\Retail\Chain;
use App\Domain\Retail\ChainFormat;
use App\Domain\Retail\RetailGroup;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Seeder;

/** Local-only sample data so the admin screens have something to show. */
class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $rice = $this->product(
            group: ['Basmati Rice', 'rice-grains', 'kg', '1'],
            brand: 'India Gate',
            name: 'India Gate Classic Basmati Rice',
            optionName: 'Size',
            variants: [['1 kg', 1, '1', 'kg'], ['5 kg', 1, '5', 'kg'], ['10 kg', 1, '10', 'kg']],
            attributes: ['origin' => 'IN'],
        );

        $this->product(
            group: ['Fresh Milk', 'dairy-eggs', 'l', '1'],
            brand: 'Almarai',
            name: 'Almarai Full Fat Fresh Milk',
            optionName: 'Size',
            variants: [['1 L', 1, '1', 'l'], ['2 L', 1, '2', 'l'], ['4 × 1 L', 4, '1', 'l']],
            attributes: ['fat_content' => 'full'],
        );

        $this->product(
            group: ['Banana', 'fruits-vegetables', 'kg', '1'],
            brand: null,
            name: 'Banana',
            optionName: 'Origin',
            variants: [['Philippines', 1, '1', 'kg'], ['Ecuador', 1, '1', 'kg']],
            attributes: [],
            variableWeight: true,
        );

        $group = RetailGroup::updateOrCreate(['slug' => 'lulu-group'], ['name' => ['en' => 'Lulu Group']]);
        $chain = Chain::updateOrCreate(['slug' => 'lulu-hypermarket'], [
            'retail_group_id' => $group->id,
            'country_id' => Country::where('code', 'AE')->value('id'),
            'name' => ['en' => 'Lulu Hypermarket'],
            'format' => ChainFormat::Hypermarket,
        ]);
        $chain->branches()->updateOrCreate(['slug' => 'al-barsha'], [
            'name' => ['en' => 'Al Barsha'],
            'region_id' => Region::where('slug', 'al-barsha')->value('id'),
            'location' => Point::makeGeodetic(25.1135, 55.1990),
        ]);

        $this->command?->info("Demo catalog ready: {$rice->name} and 2 more products, 1 chain with 1 branch.");
    }

    /** @param  array{0: string, 1: string, 2: string, 3: string}  $group  name, category slug, comparison unit, quantity */
    private function product(array $group, ?string $brand, string $name, string $optionName, array $variants, array $attributes, bool $variableWeight = false): Product
    {
        $category = Category::where('slug', $group[1])->firstOrFail();
        $productGroup = ProductGroup::updateOrCreate(['slug' => str($group[0])->slug()], [
            'category_id' => $category->id,
            'name' => ['en' => $group[0]],
            'comparison_unit_code' => $group[2],
            'comparison_quantity' => $group[3],
        ]);

        $product = Product::updateOrCreate(['slug' => str($name)->slug()], [
            'product_group_id' => $productGroup->id,
            'category_id' => $category->id,
            'brand_id' => $brand ? Brand::updateOrCreate(['slug' => str($brand)->slug()], ['name' => ['en' => $brand]])->id : null,
            'name' => ['en' => $name],
            'attributes' => $attributes,
            'is_variable_weight' => $variableWeight,
            'status' => ProductStatus::Active,
        ]);

        $option = $product->options()->updateOrCreate(['sort' => 0], ['name' => ['en' => $optionName]]);

        foreach ($variants as $sort => [$label, $packCount, $unitQty, $unitCode]) {
            $value = $option->values()->updateOrCreate(['code' => str($label)->slug()], ['label' => ['en' => $label], 'sort' => $sort]);
            $variant = $product->variants()->updateOrCreate(['slug' => str($label)->slug()], [
                'pack_count' => $packCount,
                'unit_qty' => $unitQty,
                'unit_code' => $unitCode,
                'is_default' => $sort === 0,
                'sort' => $sort,
            ]);
            $variant->syncOptionValues([$value->id]);
        }

        return $product;
    }
}
