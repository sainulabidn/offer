<?php

namespace App\Domain\Brochures;

use App\Domain\Access\Access;
use App\Filament\Resources\Brochures\BrochureResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Admin panel notifications about a brochure: to the people who may publish it, or to anyone given.
 * Works in jobs too, where nobody is signed in.
 */
final class BrochureNotifier
{
    /** @return Collection<int, User> active users allowed to publish this brochure */
    public static function publishers(Brochure $brochure): Collection
    {
        $names = collect(['all', 'assigned', 'own'])->map(fn (string $level) => "brochures.publish.{$level}")->all();

        return User::query()
            ->whereNull('disabled_at')
            ->where(fn ($q) => $q->permission($names)->orWhereHas('roles', fn ($q) => $q->where('name', 'super_admin')))
            ->get()
            ->filter(fn (User $user) => Access::allows($user, 'brochures', 'publish', $brochure))
            ->values();
    }

    public static function waitingForReview(Brochure $brochure, ?User $by): void
    {
        $chain = $brochure->loadMissing('chain')->chain?->name ?? '';
        self::send(self::publishers($brochure), $brochure, "Brochure waiting for review: {$brochure->title}", $chain.($by ? " · submitted by {$by->name}" : ''));
    }

    /**
     * @param  iterable<User>  $users
     * @param  string  $page  the brochure page to link to: "edit" or "review"
     */
    public static function send(iterable $users, Brochure $brochure, string $title, string $body = '', string $page = 'edit'): void
    {
        $users = collect($users)->unique('id');
        if ($users->isEmpty()) {
            return;
        }

        Notification::make()
            ->title($title)
            ->body($body)
            ->actions([Action::make('open')->url(BrochureResource::getUrl($page, ['record' => $brochure], panel: 'admin'))])
            ->sendToDatabase($users);
    }
}
