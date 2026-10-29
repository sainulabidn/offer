<?php

namespace App\Filament\Resources\Synonyms;

use App\Domain\Search\Synonym;
use App\Filament\Resources\Synonyms\Pages\CreateSynonym;
use App\Filament\Resources\Synonyms\Pages\EditSynonym;
use App\Filament\Resources\Synonyms\Pages\ListSynonyms;
use App\Filament\Resources\Synonyms\Schemas\SynonymForm;
use App\Filament\Resources\Synonyms\Tables\SynonymsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SynonymResource extends Resource
{
    protected static ?string $model = Synonym::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 9;

    protected static ?string $navigationLabel = 'Search synonyms';

    protected static ?string $modelLabel = 'synonym';

    public static function form(Schema $schema): Schema
    {
        return SynonymForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SynonymsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSynonyms::route('/'),
            'create' => CreateSynonym::route('/create'),
            'edit' => EditSynonym::route('/{record}/edit'),
        ];
    }
}
