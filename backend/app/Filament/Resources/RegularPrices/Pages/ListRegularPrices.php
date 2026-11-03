<?php

namespace App\Filament\Resources\RegularPrices\Pages;

use App\Filament\Imports\ShelfPriceImporter;
use App\Filament\Pages\MissingShelfPrices;
use App\Filament\Resources\RegularPrices\RegularPriceResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListRegularPrices extends ListRecords
{
    protected static string $resource = RegularPriceResource::class;

    protected ?string $subheading = 'Shown on product cards, with their date, when a store has no offer on an item. Search picks up changes within 30 minutes.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('missing')->label('Missing prices')->color('gray')->url(MissingShelfPrices::getUrl()),
            ImportAction::make()
                ->label('Import prices (CSV)')
                ->importer(ShelfPriceImporter::class)
                ->visible(fn () => RegularPriceResource::canCreate())
                // Keeps Filament's "Download example CSV" link, which a plain text description would replace.
                ->modalDescription(fn (ImportAction $action) => new HtmlString(
                    'One row per price. Excel: File → Save as → CSV UTF-8. Download the example file for the columns.<br>'
                    .$action->getModalAction('downloadExample')->toHtml()
                )),
            CreateAction::make(),
        ];
    }
}
