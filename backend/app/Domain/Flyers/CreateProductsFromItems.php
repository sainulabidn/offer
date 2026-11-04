<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureItem;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Catalog\CatalogRowImporter;
use App\Domain\Catalog\PackSize;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductGroup;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Flyer items with no product in the catalog yet: makes each product and pack (CatalogRowImporter, so the
 * usual catalog rules apply — products by people without "approve" wait for review before shoppers see
 * them), then accepts the item as a draft offer on the brochure, for its store and branches. Accepting
 * also teaches the matcher the name, so next week's flyer matches it by itself.
 *
 * An existing product is never changed: a name that is already in the catalog is reported, to pick instead.
 */
final class CreateProductsFromItems
{
    public function __construct(private readonly CatalogRowImporter $catalog, private readonly AcceptItems $accept) {}

    /**
     * @param  array<int, array{name: string, brand?: ?string, size: string, product_group_id: int|string}>  $rows  by item id
     * @return array{created: int, failed: array<int, string>} failed: item id => why
     */
    public function __invoke(Brochure $brochure, array $rows, ?User $user): array
    {
        $result = ['created' => 0, 'failed' => []];
        foreach ($rows as $itemId => $row) {
            $item = $brochure->items()->with(['brochure', 'offer'])->find($itemId);
            if (! $item || $item->status === ItemStatus::Accepted || $item->product_variant_id !== null) {
                continue;
            }
            try {
                $this->create($item, $row, $user);
                $result['created']++;
            } catch (InvalidArgumentException|ValidationException $e) {
                $result['failed'][$item->id] = $e instanceof ValidationException ? (collect($e->errors())->flatten()->first() ?? $e->getMessage()) : $e->getMessage();
            }
        }

        return $result;
    }

    private function create(BrochureItem $item, array $row, ?User $user): void
    {
        $name = trim((string) $row['name']);
        $size = PackSize::parse($row['size'] ?? null)
            ?? throw new InvalidArgumentException("{$name}: can't read the size \"{$row['size']}\". Write it like 500 g, 1.5 L, 6 x 200 ml or per kg.");
        $group = ProductGroup::with('category')->find($row['product_group_id'] ?? null)
            ?? throw new InvalidArgumentException("{$name}: choose a product group.");
        if (Product::query()->where('slug', Str::slug($name))->exists()) {
            throw new InvalidArgumentException("{$name} is already in the catalog: pick it with \"Edit and accept\".");
        }

        $variant = $this->catalog->import([
            // Slugs, so the existing group and category are found, never duplicated.
            'category' => $group->category->slug,
            'product_group' => $group->slug,
            'brand' => trim((string) ($row['brand'] ?? '')) ?: null,
            'product_name' => $name,
            'product_name_ar' => $item->name_ar,
            'pack_count' => $size->packCount,
            'unit_qty' => $size->unitQty,
            'unit' => $size->unit->code,
            'sold_by_weight' => $size->byWeight,
        ]);

        $item->update([
            'name' => $name,
            'brand_text' => trim((string) ($row['brand'] ?? '')) ?: null,
            'size_text' => trim((string) $row['size']),
            'product_variant_id' => $variant->id,
            'match_method' => 'created',
            'issues' => [],
        ]);
        $this->accept->accept($item->refresh(), $user);
    }
}
