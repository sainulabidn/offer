<?php

namespace App\Filament\Imports;

use App\Domain\Catalog\CatalogRowImporter;
use App\Domain\Catalog\ProductVariant;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * Bulk catalog import, one CSV row per variant. Filament handles upload, column mapping, validation
 * and the failed-rows download; CatalogRowImporter does the find-or-create work.
 */
class CatalogImporter extends Importer
{
    protected static ?string $model = ProductVariant::class;

    public static function getColumns(): array
    {
        $column = fn (string $name, string $example, bool $required = false) => ImportColumn::make($name)
            ->example($example)
            ->requiredMapping($required)
            ->rules($required ? ['required'] : ['nullable'])
            ->fillRecordUsing(fn () => null); // CatalogRowImporter fills everything in saveRecord()

        return [
            $column('category', 'rice-grains', true),
            $column('product_group', 'Basmati Rice', true),
            $column('brand', 'India Gate'),
            $column('product_name', 'India Gate Classic Basmati Rice', true),
            $column('product_name_ar', ''),
            $column('description', ''),
            $column('option_name', 'Size'),
            $column('option_value', '5 kg'),
            $column('pack_count', '1')->rules(['nullable', 'integer', 'min:1']),
            $column('unit_qty', '5', true)->rules(['required', 'numeric', 'gt:0']),
            $column('unit', 'kg', true),
            $column('gtin', '8901234567890')->rules(['nullable', 'digits_between:8,14']),
            $column('origin', 'IN')->rules(['nullable', 'alpha', 'size:2']),
            $column('sold_by_weight', 'no'),
        ];
    }

    public function resolveRecord(): ProductVariant
    {
        return new ProductVariant;
    }

    public function saveRecord(): void
    {
        try {
            $this->record = app(CatalogRowImporter::class)->import($this->data);
        } catch (InvalidArgumentException $e) {
            throw new RowImportFailedException($e->getMessage());
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Catalog import finished: '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failed = $import->getFailedRowsCount()) {
            $body .= ' '.Number::format($failed).' '.str('row')->plural($failed).' failed; download them to see why.';
        }

        return $body;
    }
}
