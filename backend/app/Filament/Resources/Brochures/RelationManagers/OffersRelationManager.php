<?php

namespace App\Filament\Resources\Brochures\RelationManagers;

use App\Domain\Access\Access;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Filament\Support\OfferFields;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Offer entry, catalog first: pick an existing variant, type the price and promotion.
 * Unit price, discount, chain and dates are derived when saving (OfferPricer).
 */
class OffersRelationManager extends RelationManager
{
    protected static string $relationship = 'offers';

    /** Without the publish permission, a live brochure's offers can be seen but not changed. */
    public function isReadOnly(): bool
    {
        $brochure = $this->getOwnerRecord();

        return ! Access::allows(Access::user(), 'brochures', 'edit', $brochure)
            || ($brochure->status === BrochureStatus::Published && ! $this->canPublish());
    }

    private function canPublish(): bool
    {
        return Access::allows(Access::user(), 'brochures', 'publish', $this->getOwnerRecord());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(4)
            ->components([
                OfferFields::variant(),
                ...OfferFields::prices(fn () => $this->getOwnerRecord()->chain_id),
                // Publishing is a permission: others leave offers as draft/approved and submit the brochure.
                Select::make('status')
                    ->options(fn () => collect(OfferStatus::cases())
                        ->reject(fn (OfferStatus $s) => ! $this->canPublish() && in_array($s, [OfferStatus::Published, OfferStatus::Expired], true))
                        ->mapWithKeys(fn (OfferStatus $s) => [$s->value => $s->getLabel()]))
                    ->default(OfferStatus::Draft)
                    ->required(),
                ...OfferFields::promotion(),
                DatePicker::make('valid_from')->native(false)->helperText('Empty = brochure dates'),
                DatePicker::make('valid_to')->native(false)->afterOrEqual('valid_from'),
                ...OfferFields::times(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['variant.product', 'variant.optionValues', 'variant.product.productGroup']))
            ->columns([
                TextColumn::make('variant')->label('Product')->state(fn (Offer $offer) => $offer->variant->label())->wrap(),
                TextColumn::make('price')->money('AED')->sortable(),
                TextColumn::make('regular_price')->label('Was')->money('AED')->placeholder('—'),
                TextColumn::make('effective_unit_price')
                    ->label('Unit price')
                    ->state(fn (Offer $offer) => $offer->effective_unit_price === null ? null : sprintf(
                        'AED %.2f / %s%s',
                        $offer->effective_unit_price,
                        (float) $offer->variant->product->productGroup->comparison_quantity === 1.0 ? '' : rtrim(rtrim((string) $offer->variant->product->productGroup->comparison_quantity, '0'), '.').' ',
                        $offer->variant->product->productGroup->comparison_unit_code,
                    ))
                    ->placeholder('—'),
                TextColumn::make('discount_pct')->label('Off')->suffix('%')->placeholder('—')->sortable(),
                TextColumn::make('promo_type')->badge(),
                TextColumn::make('audience')
                    ->label('Who')
                    ->state(fn (Offer $offer) => $offer->isStaffOnly() ? 'Registered customers only' : null)
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(OfferStatus::class),
            ])
            ->headerActions([
                CreateAction::make()->createAnother(true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
