<?php

namespace App\Filament\Resources\RegularPrices\Pages;

use App\Domain\Offers\ShelfPrices;
use App\Filament\Resources\RegularPrices\RegularPriceResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRegularPrice extends CreateRecord
{
    protected static string $resource = RegularPriceResource::class;

    /** One price per pack, store and day: a second entry for the same day corrects the first. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ShelfPrices::class)->record((int) $data['product_variant_id'], (int) $data['chain_id'], (string) $data['price'], $data['observed_at']);
    }
}
