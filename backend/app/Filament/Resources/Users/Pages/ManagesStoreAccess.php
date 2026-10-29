<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\UserStoreAccess;
use App\Models\User;

/** The Stores section and the Disabled switch aren't user columns: they're taken out of the form data and saved here. */
trait ManagesStoreAccess
{
    /** @var array<string, list<int>> store IDs by type, saved after the user */
    private array $stores = [];

    private function takeStoreFields(array $data): array
    {
        $fields = ['store_groups' => 'retail_group', 'store_chains' => 'chain', 'store_branches' => 'branch'];
        $this->stores = [];
        foreach ($fields as $field => $type) {
            $this->stores[$type] = ($data['all_stores'] ?? false) ? [] : array_map('intval', $data[$field] ?? []);
            unset($data[$field]);
        }
        if (array_key_exists('is_disabled', $data)) {
            $data['disabled_at'] = $data['is_disabled'] ? ($this->record?->disabled_at ?? now()) : null;
            unset($data['is_disabled']);
        }

        return $data;
    }

    private function saveStores(User $user): void
    {
        UserStoreAccess::sync($user, $this->stores);
    }
}
