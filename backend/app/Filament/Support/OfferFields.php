<?php

namespace App\Filament\Support;

use App\Domain\Access\Access;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Offers\Offer;
use App\Domain\Offers\PriceWait;
use App\Domain\Offers\PromoType;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;

/** The offer form's product, price, promotion and condition fields, shared by brochure entry and the Offers page. */
final class OfferFields
{
    public static function variant(): Select
    {
        return Select::make('product_variant_id')
            ->label('Product and variant')
            ->required()
            ->searchable()
            ->columnSpanFull()
            ->live()
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
            ->helperText('Search by product name or barcode. Missing? Add it under Catalog → Products first.');
    }

    /**
     * Price (with the store users' wait, PriceWait), "was" price and promotion type.
     *
     * @param  Closure(Get, ?Offer): ?int  $chainId  the store the offer is for
     */
    public static function prices(Closure $chainId): array
    {
        return [
            TextInput::make('price')->label('Offer price (AED)')->numeric()->gt(0)->required()
                ->rule(fn (Get $get, ?Offer $record) => function (string $attribute, mixed $value, Closure $fail) use ($get, $record, $chainId) {
                    $variant = (int) $get('product_variant_id');
                    $chain = $chainId($get, $record);
                    if (! $variant || ! $chain || ($record?->exists && ! self::priceChanged($record, $get))) {
                        return;
                    }
                    if ($message = PriceWait::message(Access::user(), $chain, $variant)) {
                        $fail($message);
                    }
                }),
            TextInput::make('regular_price')->label('Was price')->numeric()->gt(0),
            Select::make('promo_type')
                ->label('Promotion')
                ->options(PromoType::class)
                ->default(PromoType::Price)
                ->required()
                ->live(),
        ];
    }

    /** Multi-buy and buy-n-get-m details, the promotion and limit texts, and who may buy. */
    public static function promotion(): array
    {
        return [
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
            // Shown with a label; shoppers can hide these, and they never count in comparisons, scores or alerts.
            Toggle::make('conditions.staff_only')->label('Store employees only')->inline(false),
            TextInput::make('condition_label.en')->label('Limit text')->placeholder('e.g. Max 3 kg per customer')->columnSpan(2)
                ->helperText('As the brochure words it: "Min 2 pcs", "Max 2 per customer"…'),
            TextInput::make('condition_label.ar')->label('Limit text (Arabic)')->extraInputAttributes(['dir' => 'rtl'])->columnSpan(2),
        ];
    }

    /**
     * A daily window, e.g. 8:00–11:00 on each day of the offer. Off = the whole day. Times hidden by switching
     * it off are still saved, as empty, so an offer can go back to whole days.
     */
    public static function times(): array
    {
        $timed = fn (Get $get) => (bool) $get('timed');

        return [
            Toggle::make('timed')
                ->label('Only at certain times')
                ->helperText('e.g. 8:00 AM – 11:00 AM on each day. Off = all day.')
                ->inline(false)
                ->live()
                ->dehydrated(false)
                ->afterStateHydrated(fn (Toggle $component, ?Offer $record) => $component->state($record?->isTimed() ?? false))
                ->afterStateUpdated(function (bool $state, Set $set) {
                    if (! $state) {
                        $set('start_time', null);
                        $set('end_time', null);
                    }
                }),
            TimePicker::make('start_time')->label('From time')->seconds(false)
                ->visible($timed)->required($timed)->dehydratedWhenHidden(),
            TimePicker::make('end_time')->label('Until time')->seconds(false)->after('start_time')
                ->visible($timed)->required($timed)->dehydratedWhenHidden(),
        ];
    }

    /** Did the form change what the shopper pays (Offer::PRICE_FIELDS)? */
    private static function priceChanged(Offer $record, Get $get): bool
    {
        $same = fn ($a, $b) => ($a === null || $a === '') && ($b === null || $b === '')
            || (is_numeric($a) && is_numeric($b) && bccomp((string) $a, (string) $b, 2) === 0);
        $type = $get('promo_type');

        return (int) $get('product_variant_id') !== $record->product_variant_id
            || ! $same($get('price'), $record->price)
            || ! $same($get('regular_price'), $record->regular_price)
            || ($type instanceof PromoType ? $type : PromoType::tryFrom((string) $type)) !== $record->promo_type
            || array_filter((array) $get('promo_params'), fn ($v) => $v !== null && $v !== '') != array_filter($record->promo_params ?? [], fn ($v) => $v !== null && $v !== '');
    }

    public static function is(mixed $state, PromoType $type): bool
    {
        return $state === $type || $state === $type->value;
    }
}
