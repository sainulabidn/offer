<?php

namespace App\Filament\Resources\Branches\Schemas;

use App\Filament\Support\Fields;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BranchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Branch')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Fields::belongsTo('chain_id', 'chain', 'Chain')->required(),
                        Fields::belongsTo('region_id', 'region', 'Area'),
                        Fields::translatable('name', 'Branch name', fillsSlug: true)->columnSpanFull(),
                        // Unique within the chain only: two chains can both have an "al-barsha" branch.
                        TextInput::make('slug')
                            ->required()
                            ->alphaDash()
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => $rule->where('chain_id', $get('chain_id'))),
                        TextInput::make('phone')->tel(),
                        Fields::translatable('address', 'Address', required: false)->columnSpanFull(),
                    ]),
                Section::make('Location')
                    ->columnSpanFull()
                    ->columns(2)
                    ->description('Copy the coordinates from Google Maps (right-click the store → the first line). A map picker comes in Phase 1.')
                    ->schema([
                        TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90)->required(),
                        TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180)->required(),
                    ]),
                Section::make('More')
                    ->columnSpanFull()
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('whatsapp')->tel(),
                        TagsInput::make('services')->suggestions(['parking', 'delivery', '24h', 'pharmacy', 'atm', 'click_collect']),
                        Toggle::make('is_affiliate')
                            ->label('Run by a franchisee / affiliate')
                            ->live()
                            ->inline(false),
                        Fields::belongsTo('operator_group_id', 'operatorGroup', 'Operating company')
                            ->visible(fn (Get $get) => (bool) $get('is_affiliate')),
                        Toggle::make('is_active')->default(true)->inline(false),
                    ]),
            ]);
    }
}
