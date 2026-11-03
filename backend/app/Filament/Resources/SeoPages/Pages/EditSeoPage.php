<?php

namespace App\Filament\Resources\SeoPages\Pages;

use App\Domain\Seo\SeoPage;
use App\Filament\Resources\SeoPages\SeoPageResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSeoPage extends EditRecord
{
    protected static string $resource = SeoPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open on the website')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (SeoPage $record) => rtrim((string) config('app.frontend_url'), '/').$record->path, shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
