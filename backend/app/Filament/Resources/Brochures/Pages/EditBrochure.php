<?php

namespace App\Filament\Resources\Brochures\Pages;

use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Filament\Resources\Brochures\BrochureResource;
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
                    $this->record->update(['reviewed_by' => auth()->id()]);
                    Notification::make()->title("{$count} offers published")->success()->send();
                }),
            DeleteAction::make(),
        ];
    }
}
