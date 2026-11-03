<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Domain\Access\Access;
use App\Domain\Offers\PriceWait;
use App\Filament\Imports\OfferImporter;
use App\Filament\Imports\OfferImportGuide;
use App\Filament\Resources\Offers\Actions\OfferSheetAction;
use App\Filament\Resources\Offers\OfferResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    protected ?string $subheading = 'Offers from brochures, and ones added here or by CSV. Added offers go live straight away.';

    protected function getHeaderActions(): array
    {
        return [
            OfferSheetAction::make(),
            Action::make('importHelp')
                ->label('How to import')
                ->icon(Heroicon::OutlinedQuestionMarkCircle)
                ->color('gray')
                ->visible(fn () => OfferResource::canCreate())
                ->slideOver()
                ->modalWidth('3xl')
                ->modalHeading('Importing offers from a CSV')
                ->modalContent(fn () => view('filament.offers.import-help', [
                    'columns' => OfferImportGuide::COLUMNS,
                    'sample' => OfferImportGuide::sample(Access::user()),
                    'hours' => PriceWait::hours(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->extraModalFooterActions([
                    Action::make('sample')->label('Sample CSV')->icon(Heroicon::OutlinedArrowDownTray)
                        ->action(fn () => OfferImportGuide::sampleCsv(Access::user())),
                    Action::make('stores')->label('Your stores and branches')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                        ->action(fn () => OfferImportGuide::storesCsv(Access::user())),
                    Action::make('packs')->label('Pack list')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                        ->action(fn () => OfferImportGuide::packsCsv()),
                ]),
            ImportAction::make()
                ->label('Import offers (CSV)')
                ->importer(OfferImporter::class)
                ->visible(fn () => OfferResource::canCreate())
                // Keeps Filament's "Download example CSV" link, which is filled from the person's own store.
                ->modalDescription(fn (ImportAction $action) => new HtmlString(
                    'One row per offer; each goes live when its row is read. Excel: File → Save as → CSV UTF-8. See "How to import" for every column.<br>'
                    .$action->getModalAction('downloadExample')->toHtml()
                )),
            CreateAction::make()->label('New offer'),
        ];
    }
}
