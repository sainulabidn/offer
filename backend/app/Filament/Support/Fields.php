<?php

namespace App\Filament\Support;

use App\Domain\Access\Access;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Reusable admin fields: translatable JSON columns ({"en": …, "ar": …}) and grades. */
final class Fields
{
    public const array STORE_GRADES = [1 => '1 · Big', 2 => '2 · Medium', 3 => '3 · Small', 4 => '4 · Local shop'];

    public const array ITEM_GRADES = [1 => '1 · Daily essential', 2 => '2 · Regular', 3 => '3 · Occasional', 4 => '4 · Rarely needed'];

    /** Grades decide who ranks first, so a store owner editing their own chain doesn't get these. */
    public static function canGrade(): bool
    {
        return Access::allows(Access::user(), 'settings', 'edit');
    }

    public static function gradeInput(array $grades): Select
    {
        return Select::make('grade')->options($grades)->visible(self::canGrade(...));
    }

    public static function gradeColumn(array $grades): SelectColumn
    {
        return SelectColumn::make('grade')->options($grades)->sortable()->visible(self::canGrade(...));
    }

    public static function gradeFilter(array $grades): SelectFilter
    {
        return SelectFilter::make('grade')->options($grades);
    }

    public static function gradeBulkAction(array $grades): BulkAction
    {
        return BulkAction::make('setGrade')
            ->label('Set grade')
            ->icon('heroicon-o-star')
            ->visible(self::canGrade(...))
            ->schema([Select::make('grade')->options($grades)->required()])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data) {
                $records->each->update(['grade' => $data['grade']]);
                Notification::make()->success()->title("Grade set on {$records->count()} items")->send();
            });
    }

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

    /**
     * Searchable select for a related model with a translatable `name`; searches by slug, shows the name.
     * With an access section (e.g. "chains"), only offers what the signed-in person may see, such as their own stores.
     */
    public static function belongsTo(string $field, string $relationship, ?string $label = null, ?string $section = null): Select
    {
        return Select::make($field)
            ->label($label)
            ->relationship($relationship, 'slug', modifyQueryUsing: $section
                ? fn (Builder $query) => Access::scope($query, Access::user(), $section)
                : null)
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
