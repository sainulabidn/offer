<?php

namespace App\Filament\Resources\Brochures\Schemas;

use App\Domain\Access\Access;
use App\Domain\Geo\Region;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Retail\RetailGroup;
use App\Filament\Support\Fields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BrochureForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Brochure')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Fields::belongsTo('chain_id', 'chain', 'Chain', section: 'chains')->required()->live(),
                        Select::make('type')
                            ->options(['pdf' => 'PDF', 'images' => 'Images', 'digital' => 'Digital (web-only offers)'])
                            ->default('pdf')
                            ->required(),
                        Fields::translatable('title', 'Title', fillsSlug: true)->columnSpanFull(),
                        Fields::slug(),
                        Select::make('channel')
                            ->options(['in_store' => 'In store', 'online' => 'Online', 'both' => 'In store and online'])
                            ->default('in_store')
                            ->required(),
                        DatePicker::make('valid_from')->required()->native(false),
                        DatePicker::make('valid_to')->required()->native(false)->afterOrEqual('valid_from'),
                        FileUpload::make('original_path')
                            ->label('Brochure file')
                            ->disk('local')
                            ->directory('brochures')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(51200)
                            ->helperText('PDF or image, up to 50 MB. Kept private; shoppers see the offers.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Where it applies')
                    ->columnSpanFull()
                    ->description('Leave empty when the brochure covers every branch of the chain.')
                    ->collapsible()
                    ->schema([
                        Repeater::make('scopes')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add a rule')
                            ->schema([
                                Select::make('scope_type')
                                    ->label('Type')
                                    ->options(['region' => 'Emirate or area', 'branch' => 'One branch', 'chain' => 'Another chain', 'group' => 'Whole retail group'])
                                    ->required()
                                    ->live(),
                                Select::make('scope_id')
                                    ->label('Which')
                                    ->required()
                                    ->searchable()
                                    ->options(fn (Get $get) => match ($get('scope_type')) {
                                        'region' => Region::query()->orderBy('type')->orderBy('sort')->get()->mapWithKeys(fn (Region $r) => [$r->id => $r->name]),
                                        'branch' => Access::scope(Branch::query(), Access::user(), 'branches')->where('chain_id', $get('../../chain_id'))->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->name]),
                                        'chain' => Access::scope(Chain::query(), Access::user(), 'chains')->get()->mapWithKeys(fn (Chain $c) => [$c->id => $c->name]),
                                        'group' => Access::scope(RetailGroup::query(), Access::user(), 'retail_groups')->get()->mapWithKeys(fn (RetailGroup $g) => [$g->id => $g->name]),
                                        default => [],
                                    }),
                            ]),
                    ]),
                Textarea::make('notes')->columnSpanFull(),
            ]);
    }
}
