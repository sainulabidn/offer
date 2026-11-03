<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Domain\Access\Access;
use App\Domain\Offers\DirectOffers;
use App\Filament\Resources\Offers\OfferResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateOffer extends CreateRecord
{
    protected static string $resource = OfferResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(DirectOffers::class)->add(Arr::except($data, ['branch_ids']), $data['branch_ids'] ?? [], Access::user());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Offer is live';
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
