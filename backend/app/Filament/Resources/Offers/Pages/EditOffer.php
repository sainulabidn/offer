<?php

namespace App\Filament\Resources\Offers\Pages;

use App\Domain\Access\Access;
use App\Domain\Offers\DirectOffers;
use App\Filament\Resources\Offers\OfferResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditOffer extends EditRecord
{
    protected static string $resource = OfferResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(DirectOffers::class)->update($record, $data, Access::user());
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
