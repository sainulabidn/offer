<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Filament\Support\ScopedByAccess;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use UnitEnum;

/** Roles and their permission grid (section × action → level). */
class RoleResource extends Resource
{
    use ScopedByAccess;

    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Access';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Roles & permissions';

    private const array LABELS = [
        'super_admin' => 'Super admin',
        'data_operator' => 'Data operator',
        'reviewer' => 'Reviewer',
        'store_owner' => 'Store owner',
        'store_staff' => 'Store staff',
    ];

    /** "store_owner" → "Store owner". */
    public static function label(string $name): string
    {
        return self::LABELS[$name] ?? Str::headline($name);
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['users', 'permissions']))
            ->columns([
                TextColumn::make('name')->formatStateUsing(fn (string $state) => self::label($state))->searchable(),
                TextColumn::make('users_count')->label('Users'),
                TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->formatStateUsing(fn (int $state, Role $record) => $record->name === 'super_admin' ? 'Everything' : (string) $state),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
