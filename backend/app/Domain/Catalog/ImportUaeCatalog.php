<?php

namespace App\Domain\Catalog;

use App\Domain\Search\Synonym;
use App\Domain\Settings\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adds the starter UAE catalogue in database/data/uae-catalog.json: categories, product groups with grades
 * and icons, brands, products with their pack sizes or origins, search synonyms, and photos from
 * database/data/catalog-images. Runs on every deploy but only acts when the file changed, and only fills
 * gaps: products, groups and brands staff already have keep what they hold.
 */
final class ImportUaeCatalog
{
    private const string FILE = 'data/uae-catalog.json';

    private const string PHOTOS = 'data/catalog-images';

    private const string DONE = 'uae_catalog_hash';

    public function __construct(private readonly CatalogRowImporter $rows) {}

    /** @return array{categories: int, groups: int, products: int, photos: int, synonyms: int}|null null when already imported */
    public function __invoke(bool $force = false): ?array
    {
        $path = database_path(self::FILE);
        $hash = hash_file('sha256', $path);
        if (! $force && SiteSetting::where('key', self::DONE)->value('value') === $hash) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data['groups'] ?? null) || ! is_array($data['products'] ?? null)) {
            throw new RuntimeException('database/'.self::FILE.' is missing or broken.');
        }

        $counts = ['categories' => $this->categories($data['categories'] ?? []), 'groups' => 0, 'products' => 0, 'photos' => 0, 'synonyms' => 0];
        $groups = collect($data['groups'])->keyBy(0);
        $knownGroups = ProductGroup::query()->pluck('slug')->flip();

        foreach ($data['products'] as $product) {
            if (Product::where('slug', Str::slug($product['n']))->exists()) {
                $counts['photos'] += (int) $this->photo($product);

                continue;
            }

            [, , $category] = $groups[$product['g']] ?? throw new RuntimeException("Unknown group [{$product['g']}] for {$product['n']}.");
            DB::transaction(function () use ($product, $category) {
                foreach ($product['v'] as $variant) {
                    $this->rows->import($this->row($product, $category, $variant));
                }
            });
            $counts['products']++;
            $counts['photos'] += (int) $this->photo($product);
        }

        foreach ($groups as [$name, $arabic, , $grade, $compareAs, $icon]) {
            $group = ProductGroup::where('slug', Str::slug($name))->first();
            if (! $group) {
                continue;
            }
            $new = ! $knownGroups->has($group->slug);
            $counts['groups'] += (int) $new;
            $group->setTranslation('name', 'ar', $group->getTranslation('name', 'ar', false) ?: $arabic);
            $group->grade ??= $grade;
            $group->icon = $group->icon ?: $icon;
            if ($new) {
                $group->compare_as = CompareAs::from($compareAs);
            }
            $group->save();
        }

        foreach ($data['brands'] ?? [] as $name => $spellings) {
            $brand = Brand::where('slug', Str::slug($name))->first();
            if ($brand && blank($brand->synonyms)) {
                $brand->update(['synonyms' => $spellings]);
            }
        }

        foreach ($data['synonyms'] ?? [] as $terms) {
            if (! Synonym::query()->whereJsonContains('terms', mb_strtolower($terms[0]))->exists()) {
                // Pushed to search with the rest by search:sync-synonyms, which every deploy runs.
                Synonym::withoutEvents(fn () => Synonym::create(['terms' => Synonym::clean($terms)]));
                $counts['synonyms']++;
            }
        }

        SiteSetting::updateOrCreate(['key' => self::DONE], ['value' => $hash]);

        return $counts;
    }

    /** New subcategories under the starter ones; existing categories are left alone. */
    private function categories(array $tree): int
    {
        $added = 0;
        foreach ($tree as $parentName => $children) {
            $parent = Category::where('slug', Str::slug($parentName))->first();
            if (! $parent) {
                continue;
            }
            foreach ($children as $sort => [$name, $icon]) {
                $category = Category::firstOrNew(['slug' => Str::slug($name)]);
                if (! $category->exists) {
                    $category->fill(['name' => ['en' => $name], 'parent_id' => $parent->id, 'icon' => $icon, 'sort' => 100 + $sort])->save();
                    $added++;
                }
            }
        }

        return $added;
    }

    /** One catalogue row: a pack size ([qty, unit, pack count]) or an origin ([label, qty, unit, country]). */
    private function row(array $product, string $category, array $variant): array
    {
        $byOrigin = ($product['o'] ?? null) === 'Origin';
        [$label, $qty, $unit, $origin, $packs] = $byOrigin
            ? [$variant[0], $variant[1], $variant[2], $variant[3], 1]
            : [null, $variant[0], $variant[1], null, $variant[2] ?? 1];

        return [
            'category' => $category,
            'product_group' => $product['g'],
            'brand' => $product['b'] ?? null,
            'product_name' => $product['n'],
            'product_name_ar' => $product['ar'] ?? null,
            'option_name' => $byOrigin ? 'Origin' : 'Size',
            'option_value' => $label,
            'pack_count' => $packs,
            'unit_qty' => (string) $qty,
            'unit' => $unit,
            'origin' => $origin,
            'sold_by_weight' => $product['w'] ?? false,
        ];
    }

    /** Adds the product's photo when it has none yet. */
    private function photo(array $product): bool
    {
        $slug = Str::slug($product['n']);
        $source = database_path(self::PHOTOS."/{$slug}.jpg");
        $record = Product::where('slug', $slug)->first();
        if (! is_file($source) || ! $record || $record->images()->exists()) {
            return false;
        }

        $path = "products/{$slug}-".substr(hash_file('sha256', $source), 0, 8).'.jpg';
        GenerateImageSizes::disk()->put($path, (string) file_get_contents($source), 'public');
        // Saving queues the resized copies the website shows.
        $record->images()->create(['path' => $path, 'alt' => ['en' => $product['n']]]);

        return true;
    }
}
