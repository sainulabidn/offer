<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Domain\Access\AccessRules;
use App\Domain\Access\Level;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The permission grid: for every admin section and action, how far the role reaches.
 * "Own" = records they created, "Their stores" = also anything in the stores assigned to the user, "All" = everything.
 */
class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        $groups = collect(AccessRules::sections())->groupBy('group', preserveKeys: true);

        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Role name')
                    ->required()
                    ->maxLength(100)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->helperText('Lower case with underscores, e.g. "lulu_buyer".')
                    ->unique(ignoreRecord: true)
                    ->disabled(fn ($record) => $record !== null && in_array($record->name, User::STAFF_ROLES, true)),
                ...$groups->map(fn ($sections, string $group) => Section::make($group)
                    ->description($group === 'Stores'
                        ? 'Own = what they added · Their stores = also the groups, chains and branches assigned to the user · All = every store.'
                        : null)
                    ->collapsible()
                    ->schema($sections->map(fn (array $section, string $key) => Fieldset::make($section['label'])
                        ->columns(5)
                        ->schema(collect($section['actions'])->map(fn (array $levels, string $action) => Select::make("grid.{$key}.{$action}")
                            ->label(AccessRules::ACTIONS[$action])
                            ->placeholder('No')
                            ->options($levels === [Level::All]
                                ? ['all' => 'Yes']
                                : collect($levels)->mapWithKeys(fn (Level $level) => [$level->value => $level->label()])->all()))
                            ->values()->all()))
                        ->values()->all()))
                    ->values()->all(),
            ]);
    }

    /** "section.action.level" names → ['section' => ['action' => 'level']]. */
    public static function gridFrom(iterable $permissionNames): array
    {
        $grid = [];
        foreach ($permissionNames as $name) {
            [$section, $action, $level] = array_pad(explode('.', $name, 3), 3, null);
            if ($level && Level::tryFrom($level) && (! isset($grid[$section][$action]) || Level::from($level)->rank() > Level::from($grid[$section][$action])->rank())) {
                $grid[$section][$action] = $level;
            }
        }

        return $grid;
    }

    /** The grid back to permission names, ignoring anything AccessRules doesn't define. */
    public static function namesFrom(array $grid): array
    {
        $valid = array_flip(AccessRules::permissionNames());

        return collect($grid)->flatMap(fn ($actions, string $section) => collect($actions)
            ->filter()
            ->map(fn (string $level, string $action) => "{$section}.{$action}.{$level}")
            ->values())
            ->filter(fn (string $name) => isset($valid[$name]))
            ->values()
            ->all();
    }
}
