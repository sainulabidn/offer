<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Domain\Catalog\ProductOptionValue;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Catalog\Unit;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Purchasable packs of the product. Brochure offers attach prices to these. */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function form(Schema $schema): Schema
    {
        $productId = fn (): int => $this->getOwnerRecord()->getKey();

        return $schema
            ->columns(4)
            ->components([
                Select::make('optionValues')
                    ->label('Option values')
                    ->multiple()
                    ->relationship(
                        'optionValues',
                        'code',
                        modifyQueryUsing: fn (Builder $query) => $query->with('option')->whereHas('option', fn (Builder $q) => $q->where('product_id', $productId())),
                    )
                    ->getOptionLabelFromRecordUsing(fn (ProductOptionValue $value) => $value->option->name.': '.$value->label)
                    ->preload()
                    ->columnSpanFull()
                    ->helperText('One value per option, e.g. Size: 5 kg. Each combination can exist only once.')
                    ->rules([fn (?ProductVariant $record): Closure => function (string $attribute, $value, Closure $fail) use ($productId, $record) {
                        $ids = array_map('intval', (array) $value);
                        sort($ids);
                        $taken = ProductVariant::where('product_id', $productId())
                            ->where('option_signature', implode('-', $ids))
                            ->when($record, fn (Builder $q) => $q->whereKeyNot($record->getKey()))
                            ->exists();
                        if ($taken) {
                            $fail('Another variant already has exactly these option values.');
                        }
                    }])
                    ->saveRelationshipsUsing(fn (ProductVariant $record, ?array $state) => $record->syncOptionValues($state ?? [])),
                TextInput::make('pack_count')
                    ->label('Packs')
                    ->helperText('6 for "6 × 1.5 L"')
                    ->integer()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                TextInput::make('unit_qty')
                    ->label('Quantity per pack')
                    ->numeric()
                    ->gt(0)
                    ->required(),
                Select::make('unit_code')
                    ->label('Unit')
                    ->options(fn () => Unit::query()->orderBy('dimension')->orderBy('factor_to_base')->pluck('code', 'code'))
                    ->required(),
                TextInput::make('qty_max')
                    ->label('Max quantity')
                    ->helperText('Only for ranges like 150–170 g')
                    ->numeric(),
                TextInput::make('slug')
                    ->required()
                    ->alphaDash()
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('product_id', $productId())),
                TextInput::make('gtin')
                    ->label('Barcode (GTIN)')
                    ->maxLength(14)
                    ->unique(ignoreRecord: true),
                TextInput::make('name.en')
                    ->label('Display name override')
                    ->helperText('Optional; normally built from the product and option values.'),
                Toggle::make('is_default')->label('Default variant')->inline(false),
                TextInput::make('sort')->numeric()->default(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('optionValues.option'))
            ->recordTitleAttribute('slug')
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('optionValues.label')->label('Options')->badge(),
                TextColumn::make('pack')
                    ->label('Pack')
                    ->state(fn (ProductVariant $v) => ($v->pack_count > 1 ? $v->pack_count.' × ' : '').rtrim(rtrim((string) $v->unit_qty, '0'), '.').' '.$v->unit_code),
                TextColumn::make('net_base_qty')->label('Total (g / ml / pc)')->numeric(),
                TextColumn::make('gtin')->label('Barcode')->placeholder('—'),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('slug')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
