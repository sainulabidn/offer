<?php

namespace App\Filament\Resources\Synonyms\Pages;

use App\Domain\Search\Synonyms;
use App\Filament\Resources\Synonyms\SynonymResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListSynonyms extends ListRecords
{
    protected static string $resource = SynonymResource::class;

    protected ?string $subheading = 'Changes reach the website search straight away. Brand spellings (Brands → other spellings) are included automatically.';

    protected function getHeaderActions(): array
    {
        return [
            // Normally not needed; for after a search outage, or to check the link to Typesense.
            Action::make('sync')
                ->label('Sync to search')
                ->color('gray')
                ->action(function (Synonyms $synonyms) {
                    try {
                        Notification::make()->success()->title('Search has '.$synonyms->sync().' synonym groups.')->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Search is not reachable')->body($e->getMessage())->send();
                    }
                }),
            CreateAction::make(),
        ];
    }
}
