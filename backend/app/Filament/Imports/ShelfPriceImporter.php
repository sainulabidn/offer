<?php

namespace App\Filament\Imports;

use App\Domain\Access\Access;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Offers\RegularPrice;
use App\Domain\Offers\ShelfPrices;
use App\Domain\Retail\Chain;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

/** Shelf prices from a CSV, one row per price. A pack is found by barcode, or by product and pack slugs. */
class ShelfPriceImporter extends Importer
{
    protected static ?string $model = RegularPrice::class;

    public static function getColumns(): array
    {
        $column = fn (string $name, string $example, bool $required = false) => ImportColumn::make($name)
            ->example($example)
            ->requiredMapping($required)
            ->rules($required ? ['required'] : ['nullable'])
            ->fillRecordUsing(fn () => null); // saveRecord() does the work

        return [
            $column('store', 'carrefour', true),
            $column('barcode', '8901234567890'),
            $column('product', 'india-gate-classic-basmati-rice'),
            $column('pack', '5-kg'),
            $column('price', '54.50', true)->rules(['required', 'numeric', 'gt:0', 'max:99999']),
            $column('date', '')->rules(['nullable', 'date', 'before_or_equal:tomorrow']),
        ];
    }

    public function resolveRecord(): RegularPrice
    {
        return new RegularPrice;
    }

    public function saveRecord(): void
    {
        $chain = Chain::where('slug', trim((string) $this->data['store']))->first()
            ?? throw new RowImportFailedException("Unknown store [{$this->data['store']}]. Use the store's slug.");

        // Runs in a queue job, where no admin is signed in: ask about the person who started the import.
        if (! Access::allows($this->import->user, 'shelf_prices', 'create') || ! Access::allows($this->import->user, 'chains', 'view', $chain)) {
            throw new RowImportFailedException("You can't enter prices for {$chain->name}.");
        }

        $this->record = app(ShelfPrices::class)->record($this->variant()->id, $chain->id, (string) $this->data['price'], ($this->data['date'] ?? null) ?: null);
    }

    private function variant(): ProductVariant
    {
        $barcode = trim((string) ($this->data['barcode'] ?? ''));
        $variant = $barcode !== ''
            ? ProductVariant::where('gtin', $barcode)->first()
            : Product::where('slug', trim((string) ($this->data['product'] ?? '')))->first()?->variants()->where('slug', trim((string) ($this->data['pack'] ?? '')))->first();

        return $variant ?? throw new RowImportFailedException('Pack not found. Give its barcode, or the product and pack slugs.');
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Shelf price import finished: '.Number::format($import->successful_rows).' '.str('price')->plural($import->successful_rows).' saved.';

        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed; download them to see why.';
        }

        return $body;
    }
}
