<?php

namespace App\Filament\Resources\BrochureSources\Pages;

use App\Filament\Resources\BrochureSources\BrochureSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBrochureSources extends ManageRecords
{
    protected static string $resource = BrochureSourceResource::class;

    protected ?string $subheading = 'Where every store\'s flyers are fetched from. Sources of stores without permission are not fetched.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add source')->mutateDataUsing(fn (array $data) => [...$data, 'next_check_at' => now()]),
        ];
    }
}
