<?php

namespace App\Filament\Resources\Brochures\RelationManagers;

use App\Domain\Access\Access;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Offers\PromoType;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
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
                Select::make('product_variant_id')
                    ->label('Product and variant')
                    ->required()
                    ->searchable()
                    ->columnSpanFull()
                    // Only products the person can see: approved ones, plus their own waiting for review.
                    ->getSearchResultsUsing(fn (string $search) => ProductVariant::query()
                        ->with(['product', 'optionValues'])
                        ->whereHas('product', fn (Builder $q) => Access::scope($q, Access::user(), 'products'))
                        ->where(fn (Builder $q) => $q
                            ->whereHas('product', fn (Builder $q) => $q->whereRaw("name->>'en' ilike ?", ['%'.$search.'%']))
                            ->orWhere('gtin', $search))
                        ->limit(30)
                        ->get()
                        ->mapWithKeys(fn (ProductVariant $v) => [$v->id => $v->label()]))
                    ->getOptionLabelUsing(fn ($value) => ProductVariant::with(['product', 'optionValues'])->find($value)?->label())
                    ->helperText('Search by product name or barcode. Missing? Add it under Catalog → Products first.'),
                TextInput::make('price')->label('Offer price (AED)')->numeric()->gt(0)->required(),
                TextInput::make('regular_price')->label('Was price')->numeric()->gt(0),
                Select::make('promo_type')
                    ->label('Promotion')
                    ->options(PromoType::class)
                    ->default(PromoType::Price)
                    ->required()
                    ->live(),
                // Publishing is a permission: others leave offers as draft/approved and submit the brochure.
                Select::make('status')
                    ->options(fn () => collect(OfferStatus::cases())
                        ->reject(fn (OfferStatus $s) => ! $this->canPublish() && in_array($s, [OfferStatus::Published, OfferStatus::Expired], true))
                        ->mapWithKeys(fn (OfferStatus $s) => [$s->value => $s->getLabel()]))
                    ->default(OfferStatus::Draft)
                    ->required(),
                TextInput::make('promo_params.qty')->label('Buy quantity (n for X)')->integer()->minValue(2)
                    ->visible(fn (Get $get) => self::is($get('promo_type'), PromoType::Multibuy))->required(fn (Get $get) => self::is($get('promo_type'), PromoType::Multibuy)),
                TextInput::make('promo_params.total')->label('For total (AED)')->numeric()->gt(0)
                    ->visible(fn (Get $get) => self::is($get('promo_type'), PromoType::Multibuy))->required(fn (Get $get) => self::is($get('promo_type'), PromoType::Multibuy)),
                TextInput::make('promo_params.buy')->label('Buy')->integer()->minValue(1)
                    ->visible(fn (Get $get) => self::is($get('promo_type'), PromoType::Bogo))->required(fn (Get $get) => self::is($get('promo_type'), PromoType::Bogo)),
                TextInput::make('promo_params.get')->label('Get free')->integer()->minValue(1)
                    ->visible(fn (Get $get) => self::is($get('promo_type'), PromoType::Bogo))->required(fn (Get $get) => self::is($get('promo_type'), PromoType::Bogo)),
                TextInput::make('promo_label.en')->label('Promotion text')->placeholder('e.g. 2 for 10')->columnSpan(2),
                TextInput::make('conditions.max_per_customer')->label('Max per customer')->integer()->minValue(1),
                Toggle::make('conditions.card_required')->label('Loyalty card price')->inline(false),
                DatePicker::make('valid_from')->native(false)->helperText('Empty = brochure dates'),
                DatePicker::make('valid_to')->native(false)->afterOrEqual('valid_from'),
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

    private static function is(mixed $state, PromoType $type): bool
    {
        return $state === $type || $state === $type->value;
    }
}
