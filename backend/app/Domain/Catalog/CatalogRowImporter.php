<?php

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Imports one catalog CSV row = one variant. Finds or creates the product group, brand, product,
 * option and option value, so the same file can be imported again to update it (no duplicates).
 *
 * Columns: category, product_group, brand, product_name, product_name_ar, description, option_name,
 * option_value, pack_count, unit_qty, unit, gtin, origin, sold_by_weight.
 */
final class CatalogRowImporter
{
    /** Default comparison unit per dimension: rice per kg, milk per litre, tissues per 100 sheets. */
    private const array COMPARISON = [
        'mass' => ['kg', '1'], 'volume' => ['l', '1'], 'count' => ['pc', '1'],
        'length' => ['m', '1'], 'sheets' => ['sheet', '100'], 'washes' => ['wash', '1'],
    ];

    public function import(array $row): ProductVariant
    {
        $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
        foreach (['category', 'product_group', 'product_name', 'unit_qty', 'unit'] as $required) {
            if (blank($row[$required] ?? null)) {
                throw new InvalidArgumentException("Column [{$required}] is required.");
            }
        }

        $unit = $this->unit($row['unit']);
        $packCount = (int) ($row['pack_count'] ?? 1) ?: 1;
        $unitQty = (string) $row['unit_qty'];
        if (! is_numeric($unitQty) || (float) $unitQty <= 0) {
            throw new InvalidArgumentException("unit_qty [{$unitQty}] must be a positive number.");
        }

        return DB::transaction(function () use ($row, $unit, $packCount, $unitQty) {
            $category = $this->category($row['category']);
            [$comparisonUnit, $comparisonQty] = self::COMPARISON[$unit->dimension->value];

            $group = ProductGroup::firstOrCreate(['slug' => Str::slug($row['product_group'])], [
                'category_id' => $category->id,
                'name' => ['en' => $row['product_group']],
                'comparison_unit_code' => $comparisonUnit,
                'comparison_quantity' => $comparisonQty,
            ]);
            if ($group->comparisonUnit->dimension !== $unit->dimension) {
                throw new InvalidArgumentException("Unit [{$unit->code}] does not match group [{$group->name}], which compares per {$group->comparison_unit_code}.");
            }

            $brand = filled($row['brand'] ?? null)
                ? Brand::firstOrCreate(['slug' => Str::slug($row['brand'])], ['name' => ['en' => $row['brand']]])
                : null;

            $name = array_filter(['en' => $row['product_name'], 'ar' => $row['product_name_ar'] ?? null]);
            $product = Product::firstOrNew(['slug' => Str::slug($row['product_name'])]);
            $product->fill([
                'product_group_id' => $group->id,
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'name' => $name,
                'is_variable_weight' => filter_var($row['sold_by_weight'] ?? false, FILTER_VALIDATE_BOOL),
                'status' => $product->status ?? ProductStatus::Active,
            ]);
            if (filled($row['description'] ?? null)) {
                $product->description = ['en' => $row['description']];
            }
            $product->save();

            $optionName = ($row['option_name'] ?? null) ?: 'Size';
            $option = $product->options()->whereRaw("name->>'en' = ?", [$optionName])->first()
                ?? $product->options()->create(['name' => ['en' => $optionName], 'sort' => $product->options()->count()]);

            $label = ($row['option_value'] ?? null) ?: $this->packLabel($packCount, $unitQty, $unit->code);
            $value = $option->values()->firstOrCreate(['code' => Str::slug($label)], ['label' => ['en' => $label], 'sort' => $option->values()->count()]);

            $gtin = ($row['gtin'] ?? null) ?: null;
            $variant = ($gtin ? ProductVariant::where('gtin', $gtin)->first() : null)
                ?? $product->variants()->where('option_signature', (string) $value->id)->first()
                ?? new ProductVariant(['product_id' => $product->id]);

            $attributes = $variant->attributes ?? [];
            if (filled($row['origin'] ?? null)) {
                $attributes['origin'] = strtoupper($row['origin']);
            }

            $variant->fill([
                'product_id' => $product->id,
                'slug' => $variant->slug ?? Str::slug($label),
                'gtin' => $gtin,
                'pack_count' => $packCount,
                'unit_qty' => $unitQty,
                'unit_code' => $unit->code,
                'attributes' => $attributes,
                'is_default' => $variant->exists ? $variant->is_default : ! $product->variants()->exists(),
            ])->save();
            $variant->syncOptionValues([$value->id]);

            return $variant;
        });
    }

    /** Accepts the code or any brochure spelling listed in units.aliases (kgs, ltr, pcs…). */
    private function unit(string $value): Unit
    {
        $needle = mb_strtolower($value);

        return Unit::find($needle)
            ?? Unit::all()->first(fn (Unit $u) => in_array($needle, $u->aliases ?? [], true))
            ?? throw new InvalidArgumentException("Unknown unit [{$value}].");
    }

    private function category(string $value): Category
    {
        return Category::where('slug', Str::slug($value))->first()
            ?? Category::whereRaw("lower(name->>'en') = ?", [mb_strtolower($value)])->first()
            ?? throw new InvalidArgumentException("Unknown category [{$value}]. Create it first under Catalog → Categories.");
    }

    private function packLabel(int $packCount, string $qty, string $unit): string
    {
        // Drop trailing decimal zeros only ("1.50" → "1.5", "200" stays "200").
        if (str_contains($qty, '.')) {
            $qty = rtrim(rtrim($qty, '0'), '.');
        }

        return ($packCount > 1 ? "{$packCount} × " : '').$qty.' '.$unit;
    }
}
