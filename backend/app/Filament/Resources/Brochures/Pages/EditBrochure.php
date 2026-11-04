<?php

namespace App\Filament\Resources\Brochures\Pages;

use App\Domain\Access\Access;
use App\Domain\Brochures\BrochureNotifier;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Flyers\StartFlyerImport;
use App\Filament\Resources\Brochures\BrochureResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EditBrochure extends EditRecord
{
    protected static string $resource = BrochureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')
                ->label('Review flyer')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn () => $this->record->isFlyerImport())
                ->url(fn () => BrochureResource::getUrl('review', ['record' => $this->record])),
            // A brochure added with its file on this page: let the AI read it like an imported flyer.
            Action::make('extract')
                ->label('Read offers with AI')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('gray')
                ->visible(fn () => ! $this->record->isFlyerImport()
                    && filled($this->record->original_path)
                    && Access::allows(Access::user(), 'brochures', 'ai_import')
                    && Access::allows(Access::user(), 'brochures', 'edit', $this->record))
                ->requiresConfirmation()
                ->modalDescription('The AI reads each page of the brochure file. You check the offers before they go live. Uses the AI budget.')
                ->action(function (StartFlyerImport $start, Action $action) {
                    try {
                        $start->existing(Access::user(), $this->record);
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                        $action->halt();
                    }
                    $this->redirect(BrochureResource::getUrl('review', ['record' => $this->record]));
                }),
            Action::make('publish')
                ->label(fn () => $this->record->status === BrochureStatus::Published ? 'Publish new offers' : 'Publish')
                ->icon('heroicon-o-rocket-launch')
                ->visible(fn () => $this->canPublish())
                ->requiresConfirmation()
                ->modalDescription(fn () => self::publishDescription($this->record))
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
                    && ! in_array($this->record->status, [BrochureStatus::InReview, BrochureStatus::Published, BrochureStatus::Processing], true)
                    && Access::allows(Access::user(), 'brochures', 'edit', $this->record))
                ->requiresConfirmation()
                ->modalDescription('Staff check the offers and publish them to the website.')
                ->action(function () {
                    $this->record->update(['status' => BrochureStatus::InReview]);
                    BrochureNotifier::waitingForReview($this->record, Access::user());
                    Notification::make()->title('Sent for review')->success()->send();
                }),
            DeleteAction::make(),
        ];
    }

    /** What publishing does, and what it leaves out: flyer items nobody accepted yet. */
    public static function publishDescription($brochure): string
    {
        $text = 'Draft and approved offers go live for every branch this brochure covers.';
        $open = $brochure->items()->whereIn('status', [ItemStatus::New, ItemStatus::Matched])->count();

        return $open ? "{$text} {$open} flyer ".str('item')->plural($open).' not accepted yet '.($open === 1 ? 'is' : 'are').' left out.' : $text;
    }

    private function canPublish(): bool
    {
        return Access::allows(Access::user(), 'brochures', 'publish', $this->record);
    }
}
