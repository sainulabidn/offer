<?php

namespace App\Filament\Resources\Synonyms\Schemas;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SynonymForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TagsInput::make('terms')
                    ->label('Words that mean the same')
                    ->placeholder('Type a word or phrase, then press Enter')
                    ->helperText('English, Arabic or other spellings, e.g. atta · wheat flour · طحين. Searching any of them finds all of them.')
                    ->required()
                    ->rules(fn (Get $get) => ['array', 'min:'.($get('root') ? 1 : 2)])
                    ->validationMessages(['min' => 'Add at least two words (or one word with a one-way search term).'])
                    ->columnSpanFull(),
                TextInput::make('root')
                    ->label('One-way: only when searching for')
                    ->live(onBlur: true)
                    ->maxLength(100)
                    ->helperText('Optional. E.g. "flour": searching flour also finds the words above, but searching them doesn\'t find flour.'),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->inline(false),
                Textarea::make('notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
