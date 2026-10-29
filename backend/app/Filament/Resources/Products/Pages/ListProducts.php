<?php

namespace App\Filament\Resources\Products\Pages;

use App\Domain\Access\Access;
use App\Filament\Imports\CatalogImporter;
use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Imported rows skip the review queue, so importing is for people who may approve products.
            ImportAction::make()
                ->visible(fn () => Access::allows(Access::user(), 'products', 'approve'))
                ->label('Import catalog (CSV)')
                ->importer(CatalogImporter::class)
                ->modalDescription('One row per variant. Excel: File → Save as → CSV UTF-8. Download the example file for the columns.'),
            CreateAction::make(),
        ];
    }
}
