<?php

namespace App\Domain\Flyers;

use App\Domain\Access\Access;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureNotifier;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Brochures\PageStatus;
use App\Domain\Brochures\PublishBrochure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Wraps up a flyer once every page is read: takes the validity dates printed on it (unless the uploader
 * gave them), suggests branches from the places it names (LocationResolver), then publishes it or hands it
 * to people. Runs once per flyer: the first job to move it out of Processing does the work.
 *
 * Auto-publish (FlyerSettings publish_mode "auto") needs a clean flyer — every item matched with nothing
 * to check, dates known, no failed or held page — and an uploader allowed to publish it; a store user
 * without that right always goes through "Submit for review".
 */
class FinishFlyer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 55;

    public function __construct(public int $brochureId) {}

    public function handle(LocationResolver $locations, AcceptItems $accept, PublishBrochure $publish): void
    {
        $brochure = Brochure::with(['pages', 'aiRequester', 'chain'])->find($this->brochureId);
        if (! $brochure || $brochure->pages->contains(fn ($page) => $page->ai_status->isOpen())) {
            return;
        }
        $claimed = Brochure::query()->whereKey($brochure->id)->where('status', BrochureStatus::Processing)
            ->update(['status' => $brochure->aiRequester ? BrochureStatus::Uploaded : BrochureStatus::InReview]);
        if ($claimed === 0) {
            return;
        }
        $brochure->refresh();

        $this->applyDetected($brochure, $locations);
        $uploader = $brochure->aiRequester;
        app(CauserResolver::class)->setCauser($uploader);

        if ($this->canAutoPublish($brochure)) {
            try {
                $result = $accept->acceptClean($brochure, $uploader);
                if ($result['failed'] === [] && $brochure->items()->whereIn('status', [ItemStatus::New, ItemStatus::Matched])->doesntExist()) {
                    $count = $publish($brochure);
                    $brochure->update(['reviewed_by' => $uploader?->id]);
                    BrochureNotifier::send($this->recipients($brochure), $brochure, "Flyer published: {$brochure->title}", "{$count} offers from {$brochure->chain->name} are live.", 'review');

                    return;
                }
            } catch (ValidationException|RuntimeException $e) {
                report($e); // falls through to review
            }
        }

        $waiting = $brochure->items()->where('status', ItemStatus::New)->count();
        $total = $brochure->items()->count();
        $failed = $brochure->pages()->where('ai_status', PageStatus::Failed)->count();
        $body = "{$brochure->chain->name}: {$total} offers read".($waiting ? ", {$waiting} need a look" : '').($failed ? ", {$failed} pages failed" : '').'.';
        BrochureNotifier::send($this->recipients($brochure), $brochure, "Flyer ready to review: {$brochure->title}", $body, 'review');
    }

    /** Dates printed on most pages, and branch suggestions from printed places, kept in brochures.detected. */
    private function applyDetected(Brochure $brochure, LocationResolver $locations): void
    {
        $outputs = $brochure->pages->pluck('ai_output')->filter();
        $mostCommon = fn (string $key) => $outputs->pluck($key)->filter()->countBy()->sortDesc()->keys()->first();
        $from = $mostCommon('from');
        $until = $mostCommon('until');
        $places = $outputs->pluck('where')->flatten()->filter()->unique()->values()->all();

        $detected = [...($brochure->detected ?? []), 'valid_from' => $from, 'valid_to' => $until, 'locations' => $places, 'branches' => $locations->suggest($brochure, $places)];
        $updates = ['detected' => $detected];
        $today = now(config('app.market_timezone'))->toDateString();
        if (! ($detected['dates_given'] ?? false) && $until !== null && $until >= $today) {
            $updates['valid_from'] = $from !== null && $from <= $until ? $from : $today;
            $updates['valid_to'] = $until;
        }
        $brochure->update($updates);
    }

    private function canAutoPublish(Brochure $brochure): bool
    {
        $detected = $brochure->detected ?? [];
        $uploader = $brochure->aiRequester;
        $min = (float) FlyerSettings::get('auto_min_confidence');

        // A source may override the setting (e.g. auto for one trusted store's feed).
        $mode = $brochure->loadMissing('brochureSource')->brochureSource?->publish_mode ?? FlyerSettings::get('publish_mode');

        return $mode === 'auto'
            && ($uploader === null || Access::allows($uploader, 'brochures', 'publish', $brochure))
            && (($detected['dates_given'] ?? false) || ($detected['valid_to'] ?? null) !== null)
            && $brochure->pages()->whereIn('ai_status', [PageStatus::Failed, PageStatus::Held])->doesntExist()
            && $brochure->items()->exists()
            && $brochure->items()->where('status', '!=', ItemStatus::Ignored)->get()->every(fn ($item) => $item->isReadyToAccept($min));
    }

    /** The uploader, or for automated flyers everyone who may publish it. */
    private function recipients(Brochure $brochure): iterable
    {
        return $brochure->aiRequester ? [$brochure->aiRequester] : BrochureNotifier::publishers($brochure);
    }
}
