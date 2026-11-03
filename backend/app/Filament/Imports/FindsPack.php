<?php

namespace App\Filament\Imports;

use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductVariant;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;

/** For CSV importers with "barcode", "product" and "pack" columns: a pack by barcode, or by product and pack slugs. */
trait FindsPack
{
    private function variant(): ProductVariant
    {
        $barcode = trim((string) ($this->data['barcode'] ?? ''));
        $variant = $barcode !== ''
            ? ProductVariant::where('gtin', $barcode)->first()
            : Product::where('slug', trim((string) ($this->data['product'] ?? '')))->first()?->variants()->where('slug', trim((string) ($this->data['pack'] ?? '')))->first();

        return $variant ?? throw new RowImportFailedException('Pack not found. Give its barcode, or the product and pack slugs.');
    }
}
