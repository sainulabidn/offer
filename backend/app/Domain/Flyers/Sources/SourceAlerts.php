<?php

namespace App\Domain\Flyers\Sources;

use App\Filament\Resources\Chains\ChainResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** Admin panel notices about flyer sources, for the people who manage them (settings editors). */
final class SourceAlerts
{
    public static function send(BrochureSource $source, string $title, string $body): void
    {
        $users = User::query()
            ->whereNull('disabled_at')
            ->where(fn ($q) => $q->permission('settings.edit.all')->orWhereHas('roles', fn ($q) => $q->where('name', 'super_admin')))
            ->get();
        if ($users->isEmpty()) {
            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->actions([Action::make('open')->url(ChainResource::getUrl('edit', ['record' => $source->chain_id], panel: 'admin'))])
            ->sendToDatabase($users);
    }
}
