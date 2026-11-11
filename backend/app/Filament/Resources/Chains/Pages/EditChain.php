<?php

namespace App\Filament\Resources\Chains\Pages;

use App\Filament\Resources\BrochureSources\BrochureSourceResource;
use App\Filament\Resources\Chains\ChainResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditChain extends EditRecord
{
    protected static string $resource = ChainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('flyer_sources')
                ->label('Flyer sources')
                ->icon(Heroicon::OutlinedRss)
                ->color('gray')
                ->url(fn () => BrochureSourceResource::urlFor(chain: $this->record))
                ->visible(fn () => BrochureSourceResource::canViewAny()),
            DeleteAction::make(),
        ];
    }
}
