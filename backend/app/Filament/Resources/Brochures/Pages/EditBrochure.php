<?php

namespace App\Filament\Resources\Brochures\Pages;

use App\Domain\Access\Access;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Filament\Resources\Brochures\BrochureResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use RuntimeException;

class EditBrochure extends EditRecord
{
    protected static string $resource = BrochureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label(fn () => $this->record->status === BrochureStatus::Published ? 'Publish new offers' : 'Publish')
                ->icon('heroicon-o-rocket-launch')
                ->visible(fn () => $this->canPublish())
                ->requiresConfirmation()
                ->modalDescription('Draft and approved offers go live for every branch this brochure covers.')
                ->action(function (PublishBrochure $publish) {
                    try {
                        $count = $publish($this->record);
                    } catch (RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->record->refresh();
                    $this->record->update(['reviewed_by' => Access::user()?->id]);
                    Notification::make()->title("{$count} offers published")->success()->send();
                }),
            // Without the publish permission: hand the brochure to someone who has it.
            Action::make('submit')
                ->label('Submit for review')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn () => ! $this->canPublish()
                    && ! in_array($this->record->status, [BrochureStatus::InReview, BrochureStatus::Published], true)
                    && Access::allows(Access::user(), 'brochures', 'edit', $this->record))
                ->requiresConfirmation()
                ->modalDescription('Staff check the offers and publish them to the website.')
                ->action(function () {
                    $this->record->update(['status' => BrochureStatus::InReview]);
                    $this->notifyPublishers();
                    Notification::make()->title('Sent for review')->success()->send();
                }),
            DeleteAction::make(),
        ];
    }

    private function canPublish(): bool
    {
        return Access::allows(Access::user(), 'brochures', 'publish', $this->record);
    }

    /** Everyone who may publish this brochure gets a notification in the admin. */
    private function notifyPublishers(): void
    {
        $names = collect(['all', 'assigned', 'own'])->map(fn (string $level) => "brochures.publish.{$level}")->all();
        $publishers = User::query()
            ->whereNull('disabled_at')
            ->where(fn ($q) => $q->permission($names)->orWhereHas('roles', fn ($q) => $q->where('name', 'super_admin')))
            ->get()
            ->filter(fn (User $user) => Access::allows($user, 'brochures', 'publish', $this->record));

        Notification::make()
            ->title("Brochure waiting for review: {$this->record->title}")
            ->body(($this->record->loadMissing('chain')->chain?->name ?? '').' · submitted by '.Access::user()?->name)
            ->actions([Action::make('open')->url(BrochureResource::getUrl('edit', ['record' => $this->record]))])
            ->sendToDatabase($publishers);
    }
}
