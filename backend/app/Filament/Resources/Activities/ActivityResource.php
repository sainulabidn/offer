<?php

namespace App\Filament\Resources\Activities;

use App\Domain\Access\AccessRules;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Support\ScopedByAccess;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/** Read-only audit trail: who created, changed or deleted what, with old → new values. */
class ActivityResource extends Resource
{
    use ScopedByAccess;

    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Access';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Activity log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['causer', 'subject']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
                TextColumn::make('causer.name')->label('Who')->placeholder('System')->searchable(),
                TextColumn::make('event')->badge()->color(fn (?string $state) => match ($state) {
                    'created' => 'success',
                    'deleted' => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('log_name')->label('Section')->formatStateUsing(fn (string $state) => AccessRules::sections()[$state]['label'] ?? Str::headline($state)),
                TextColumn::make('subject')->label('Record')->state(fn (Activity $activity) => self::describe($activity))->wrap(),
                TextColumn::make('changes')
                    ->label('Changes')
                    ->state(fn (Activity $activity) => self::changes($activity))
                    ->wrap()
                    ->html(),
            ])
            ->filters([
                SelectFilter::make('log_name')->label('Section')->options(collect(AccessRules::sections())->map->label->all()),
                SelectFilter::make('event')->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }

    private static function describe(Activity $activity): string
    {
        // Raw values: subjects differ (name, title or only a slug), and translatable names are JSON.
        $raw = $activity->subject?->getAttributes() ?? [];
        $label = $raw['name'] ?? $raw['title'] ?? $raw['slug'] ?? null;
        if (is_string($label) && str_starts_with($label, '{')) {
            $label = json_decode($label, true)['en'] ?? $label;
        }

        return class_basename((string) $activity->subject_type).' #'.$activity->subject_id.(is_string($label) && $label !== '' ? " · {$label}" : '');
    }

    /** "price: 45.95 → 39.95" per changed field; escaped, one per line. */
    private static function changes(Activity $activity): string
    {
        $new = (array) ($activity->attribute_changes?->get('attributes') ?? []);
        $old = (array) ($activity->attribute_changes?->get('old') ?? []);
        $show = fn ($value) => e(Str::limit(is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_UNICODE), 60));

        return collect($new)
            ->map(fn ($value, string $field) => '<b>'.e($field).'</b>: '.(array_key_exists($field, $old) ? $show($old[$field]).' → ' : '').$show($value))
            ->take(8)
            ->implode('<br>');
    }
}
