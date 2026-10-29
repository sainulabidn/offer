<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles')->withCount('storeAccess'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => RoleResource::label($state)),
                TextColumn::make('stores')
                    ->state(fn (User $user) => $user->all_stores ? 'All stores' : ($user->store_access_count ? "{$user->store_access_count} assigned" : '—')),
                IconColumn::make('active')->boolean()->state(fn (User $user) => $user->disabled_at === null),
                TextColumn::make('created_at')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Shoppers sign in on the website and have no role; the list shows admin users by default.
                Filter::make('staff')->label('Admin users only')->default()->query(fn (Builder $query) => $query->has('roles')),
                SelectFilter::make('roles')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Role $record) => RoleResource::label($record->name)),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
