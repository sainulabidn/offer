<?php

namespace App\Filament\Resources\SeoPages\Pages;

use App\Filament\Resources\SeoPages\SeoPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSeoPages extends ListRecords
{
    protected static string $resource = SeoPageResource::class;

    protected ?string $subheading = 'Landing pages for a search (/deals/…), and your own titles and descriptions for any other page. The website picks up changes within 5 minutes.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
