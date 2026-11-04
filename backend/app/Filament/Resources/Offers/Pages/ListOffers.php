<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Domain\Access\Access;
use App\Domain\Flyers\FlyerBudget;
use App\Domain\Flyers\StartFlyerImport;
use App\Domain\Offers\PriceWait;
use App\Domain\Retail\Chain;
use App\Filament\Imports\OfferImporter;
use App\Filament\Imports\OfferImportGuide;
use App\Filament\Imports\OfferSheet;
use App\Filament\Pages\OfferBuilder;
use App\Filament\Resources\Brochures\BrochureResource;
use App\Filament\Resources\Offers\OfferResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ListOffers extends ListRecords
{
    protected static string $resource = OfferResource::class;

    protected ?string $subheading = 'Offers from brochures, and ones added here or by CSV. Added offers go live straight away.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('builder')
                ->label('Offer builder')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->visible(fn () => OfferBuilder::canAccess())
                ->url(fn () => OfferBuilder::getUrl()),
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
            $this->importFlyerAction(),
            CreateAction::make()->label('New offer'),
        ];
    }

    /** A flyer PDF or photos of its pages, read by AI; the offers wait on the review screen until checked. */
    private function importFlyerAction(): Action
    {
        return Action::make('importFlyer')
            ->label('Import from flyer (AI)')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn () => Access::allows(Access::user(), 'brochures', 'ai_import'))
            ->modalHeading('Import offers from a flyer')
            ->modalDescription('The AI reads each page and matches the products; you check them before anything goes live. Upload one PDF, or photos of the pages in order.')
            ->modalSubmitActionLabel('Read the flyer')
            ->schema([
                Select::make('chain_id')
                    ->label('Store')
                    ->options(fn () => OfferImportGuide::chains(Access::user())->orderBy('slug')->get()->mapWithKeys(fn (Chain $chain) => [$chain->id => $chain->name]))
                    ->searchable()
                    ->required()
                    ->live(),
                Select::make('branch_ids')
                    ->label('Branches')
                    ->multiple()
                    ->options(fn (Get $get) => $get('chain_id') ? OfferSheet::branchOptions(Access::user(), [(int) $get('chain_id')]) : [])
                    ->helperText('Empty = every branch, for people who manage the whole store. A branch flyer: pick its branches.'),
                TextInput::make('title')->placeholder('e.g. Weekend deals')->maxLength(120),
                Grid::make(2)->schema([
                    DatePicker::make('valid_from')->label('From')->native(false),
                    DatePicker::make('valid_to')->label('Until')->native(false)->afterOrEqual('valid_from'),
                ]),
                Text::make('Leave the dates empty to use the ones printed on the flyer.')->color('gray'),
                FileUpload::make('files')
                    ->label('Flyer')
                    ->multiple()
                    ->reorderable()
                    ->required()
                    ->disk(config('flyers.disk'))
                    ->directory(config('flyers.directory').'/incoming')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(config('flyers.max_file_mb') * 1024)
                    ->maxFiles(StartFlyerImport::MAX_IMAGES)
                    ->helperText('One PDF up to 50 MB, or up to '.StartFlyerImport::MAX_IMAGES.' photos (JPG, PNG, WebP). Kept private.'),
                Toggle::make('economy')
                    ->label('Economy: half the AI cost, ready within a few hours')
                    ->helperText('For flyers that start in a day or more. Off: pages are read now, usually within minutes.'),
            ])
            ->action(function (array $data, StartFlyerImport $start, Action $action) {
                try {
                    $brochure = $start(
                        Access::user(),
                        Chain::findOrFail($data['chain_id']),
                        array_values((array) $data['files']),
                        array_map('intval', $data['branch_ids'] ?? []),
                        ['title' => $data['title'] ?? null, 'valid_from' => $data['valid_from'] ?? null, 'valid_to' => $data['valid_to'] ?? null, 'mode' => ($data['economy'] ?? false) ? 'economy' : 'fast'],
                    );
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Flyer not imported')->body(collect($e->errors())->flatten()->first())->send();
                    $action->halt();
                }

                match (true) {
                    ! $brochure->wasRecentlyCreated => Notification::make()->warning()->title('This flyer was imported before')->body('Opening the earlier import.')->send(),
                    ! FlyerBudget::allowsMore() => Notification::make()->warning()->title('Flyer saved, waiting for budget')->body("This month's AI budget is spent; the pages are read when an admin raises it or the month turns.")->send(),
                    default => Notification::make()->success()->title('Reading the flyer')->body('Pages appear on the review screen as they are read, usually within a few minutes.')->send(),
                };
                $this->redirect(BrochureResource::getUrl('review', ['record' => $brochure]));
            });
    }
}
