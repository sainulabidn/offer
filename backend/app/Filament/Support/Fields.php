<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Reusable admin fields for translatable JSON columns ({"en": …, "ar": …}). */
final class Fields
{
    /** English + Arabic inputs for one translatable column. When $fillsSlug, typing English fills the slug on create. */
    public static function translatable(string $column, string $label, bool $required = true, bool $fillsSlug = false, bool $textarea = false): Grid
    {
        $component = $textarea ? Textarea::class : TextInput::class;

        $english = $component::make("{$column}.en")->label("{$label} (English)")->required($required);
        if ($fillsSlug) {
            $english->live(onBlur: true)->afterStateUpdated(function (?string $state, Set $set, string $operation) {
                if ($operation === 'create') {
                    $set('slug', Str::slug((string) $state));
                }
            });
        }

        return Grid::make(2)->schema([
            $english,
            $component::make("{$column}.ar")->label("{$label} (Arabic)")->extraInputAttributes(['dir' => 'rtl']),
        ]);
    }

    public static function slug(): TextInput
    {
        return TextInput::make('slug')
            ->required()
            ->alphaDash()
            ->maxLength(255)
            ->unique(ignoreRecord: true);
    }

    /** Searchable select for a related model with a translatable `name`; searches by slug, shows the name. */
    public static function belongsTo(string $field, string $relationship, ?string $label = null): Select
    {
        return Select::make($field)
            ->label($label)
            ->relationship($relationship, 'slug')
            ->getOptionLabelFromRecordUsing(fn (Model $record) => $record->name)
            ->searchable()
            ->preload();
    }

    /** Table column for a translatable name: shows English, searches and sorts on the JSON value. */
    public static function nameColumn(string $column = 'name'): TextColumn
    {
        return TextColumn::make($column)
            ->searchable(query: fn (Builder $query, string $search) => $query->whereRaw("{$column}->>'en' ilike ?", ['%'.$search.'%']))
            ->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("{$column}->>'en' ".($direction === 'desc' ? 'desc' : 'asc')));
    }
}
