<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\Fields;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Variation dimensions (Size, Origin, Flavour…) and their values; variants then pick one value per option. */
class OptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'options';

    protected static ?string $title = 'Options';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Fields::translatable('name', 'Option name')->columnSpanFull(),
                Select::make('attribute_id')
                    ->label('Linked attribute')
                    ->relationship('attributeDefinition', 'code')
                    ->helperText('Optional. Link to an attribute such as origin so the values become search filters.'),
                TextInput::make('sort')->numeric()->default(0),
                Repeater::make('values')
                    ->relationship()
                    ->orderColumn('sort')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextInput::make('label.en')
                            ->label('Value (English)')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => blank($get('code')) ? $set('code', Str::slug((string) $state)) : null),
                        TextInput::make('label.ar')->label('Value (Arabic)')->extraInputAttributes(['dir' => 'rtl']),
                        TextInput::make('code')->required()->alphaDash()->distinct(),
                    ])
                    ->itemLabel(fn (array $state) => $state['label']['en'] ?? null)
                    ->addActionLabel('Add value'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('values'))
            ->reorderable('sort')
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('values.label')->label('Values')->badge(),
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
