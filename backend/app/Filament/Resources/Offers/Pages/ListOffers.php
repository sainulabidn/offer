<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Filament\Imports\OfferImporter;
use App\Filament\Resources\Offers\OfferResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    protected ?string $subheading = 'Offers from brochures, and ones added here or by CSV. Added offers go live straight away.';

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->label('Import offers (CSV)')
                ->importer(OfferImporter::class)
                ->visible(fn () => OfferResource::canCreate())
                ->modalDescription('One row per offer; each goes live when its row is read. Excel: File → Save as → CSV UTF-8. Download the example file for the columns.'),
            CreateAction::make()->label('New offer'),
        ];
    }
}
