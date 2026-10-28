<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    use ConvertsCoordinates;

    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['latitude'] = $this->record->location?->getLatitude();
        $data['longitude'] = $this->record->location?->getLongitude();
        // Livewire cannot hold the Point object in form state.
        unset($data['location']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->coordinatesToLocation($data);
    }
}
