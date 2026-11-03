<?php

namespace App\Filament\Resources\Offers\Schemas;

use App\Domain\Access\Access;
use App\Domain\Offers\Offer;
use App\Domain\Offers\PriceWait;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Filament\Support\Fields;
use App\Filament\Support\OfferFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class OfferForm
{
    public static function configure(Schema $schema): Schema
    {
        $hours = PriceWait::hours();

        return $schema
            ->columns(4)
            ->components([
                Fields::belongsTo('chain_id', 'chain', 'Store', section: 'chains')
                    ->required()
                    ->live()
                    ->columnSpan(2)
                    ->disabledOn('edit'),
                Select::make('branch_ids')
                    ->label('Branches')
                    ->multiple()
                    ->columnSpan(2)
                    ->visibleOn('create')
                    ->options(fn (Get $get) => $get('chain_id')
                        ? Access::scope(Branch::query()->where('chain_id', $get('chain_id'))->where('is_active', true), Access::user(), 'branches')
                            ->get()->sortBy('name')->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->name])
                        : [])
                    // People who work at some branches only pick theirs; the whole store is for those who manage it.
                    ->required(fn (Get $get) => $get('chain_id') && ! Access::allows(Access::user(), 'chains', 'edit', Chain::find($get('chain_id'))))
                    ->helperText('Empty = every branch of the store.'),
                OfferFields::variant(),
                ...OfferFields::prices(fn (Get $get, ?Offer $record) => $record?->chain_id ?? ((int) $get('chain_id') ?: null)),
                DatePicker::make('valid_from')->label('From')->native(false)->required()
                    ->default(fn () => now(config('app.market_timezone'))->toDateString()),
                ...OfferFields::promotion(),
                DatePicker::make('valid_to')->label('Until')->native(false)->required()->afterOrEqual('valid_from')
                    ->helperText($hours ? "Goes live straight away. Store users can set a pack's price once every {$hours} hours." : 'Goes live straight away.'),
            ]);
    }
}
