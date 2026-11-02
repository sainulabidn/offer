<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\UserStoreAccess;
use App\Domain\Shopper\ShopperPreference;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use ManagesStoreAccess;

    protected static string $resource = UserResource::class;

    /** @var array{radius_km: ?int, item_radius_km: ?int} */
    private array $shopping = ['radius_km' => null, 'item_radius_km' => null];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $ids = UserStoreAccess::idsFor($this->record);
        $shopping = ShopperPreference::find($this->record->id);

        return [
            ...$data,
            'store_groups' => $ids['retail_group'],
            'store_chains' => $ids['chain'],
            'store_branches' => $ids['branch'],
            'is_disabled' => $this->record->disabled_at !== null,
            'shopper_radius_km' => $shopping?->radius_km,
            'shopper_item_radius_km' => $shopping?->item_radius_km,
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->shopping = [
            'radius_km' => filled($data['shopper_radius_km'] ?? null) ? (int) $data['shopper_radius_km'] : null,
            'item_radius_km' => filled($data['shopper_item_radius_km'] ?? null) ? (int) $data['shopper_item_radius_km'] : null,
        ];
        unset($data['shopper_radius_km'], $data['shopper_item_radius_km']);

        return $this->takeStoreFields($data);
    }

    protected function afterSave(): void
    {
        $this->saveStores($this->record);

        // A newer time than any device's, so this reaches them; unchanged values leave their choices alone.
        $saved = ShopperPreference::find($this->record->id);
        if ($this->shopping !== ['radius_km' => $saved?->radius_km, 'item_radius_km' => $saved?->item_radius_km]) {
            ShopperPreference::updateOrCreate(['user_id' => $this->record->id], [...$this->shopping, 'client_updated_at' => now()]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
