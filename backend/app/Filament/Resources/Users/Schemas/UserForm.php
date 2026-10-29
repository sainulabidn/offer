<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domain\Access\Access;
use App\Domain\Retail\Branch;
use App\Domain\Retail\Chain;
use App\Domain\Retail\RetailGroup;
use App\Filament\Resources\Roles\RoleResource;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        $stores = fn (string $field, string $label, array $options) => Select::make($field)
            ->label($label)
            ->multiple()
            ->searchable()
            ->options($options)
            ->hidden(fn (Get $get) => (bool) $get('all_stores'));

        return $schema
            ->columns(2)
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                        TextInput::make('password')
                            ->password()
                            ->revealable()
                            ->rule('min:10')
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? 'Leave empty to keep the current password.'
                                : 'At least 10 characters. Share it privately; they can change it later.'),
                        Toggle::make('is_disabled')
                            ->label('Disabled (cannot sign in to the admin)')
                            ->inline(false)
                            ->disabled(fn ($record) => $record !== null && $record->is(Access::user())),
                    ]),
                Section::make('Roles')
                    ->description('What they can do. Each role\'s permissions are set under Access → Roles.')
                    ->columnSpanFull()
                    ->schema([
                        CheckboxList::make('roles')
                            ->hiddenLabel()
                            ->relationship('roles', 'name')
                            ->getOptionLabelFromRecordUsing(fn (Role $role) => RoleResource::label($role->name))
                            // Only super admins can make someone a super admin (greyed out, and checked on save).
                            ->disableOptionWhen(fn (string $value) => ! Access::isSuperAdmin(Access::user())
                                && Role::query()->whereKey($value)->where('name', 'super_admin')->exists())
                            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                                $super = Role::query()->whereIn('id', (array) $value)->where('name', 'super_admin')->exists();
                                if ($super && ! Access::isSuperAdmin(Access::user())) {
                                    $fail('Only a super admin can give the Super admin role.');
                                }
                            })
                            ->columns(3),
                    ]),
                Section::make('Stores')
                    ->description('Where they work. Permissions set to "Their stores" apply to these. A group includes all its chains and branches; a chain includes all its branches.')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        Toggle::make('all_stores')->label('All stores')->live()->columnSpanFull(),
                        $stores('store_groups', 'Retail groups', RetailGroup::query()->get()->mapWithKeys(fn (RetailGroup $g) => [$g->id => $g->name])->all()),
                        $stores('store_chains', 'Chains', Chain::query()->get()->mapWithKeys(fn (Chain $c) => [$c->id => $c->name])->all()),
                        $stores('store_branches', 'Branches', Branch::query()->with('chain')->get()
                            ->mapWithKeys(fn (Branch $b) => [$b->id => "{$b->chain->name} · {$b->name}"])->all()),
                    ]),
            ]);
    }
}
