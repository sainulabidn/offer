<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochurePage;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\PageStatus;
use App\Domain\Flyers\Sources\BrochureFetch;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps the flyer pipeline moving (flyers:sweep, every 10 minutes): pages whose worker died are queued
 * again, held pages go on when the budget allows, finished flyers are wrapped up, and uploads that never
 * became a flyer (a failed form) are deleted after a day.
 */
final class SweepFlyers
{
    /** An AI call never takes this long; a page still "extracting" after it lost its worker. */
    private const int STUCK_MINUTES = 15;

    /** @return array{requeued: int, resumed: int, checked: int, deleted: int} */
    public function __invoke(): array
    {
        $stuck = BrochurePage::query()
            ->whereIn('ai_status', [PageStatus::Extracting, PageStatus::Queued])
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->get();
        foreach ($stuck as $page) {
            $page->update(['ai_status' => PageStatus::Queued]);
            ExtractFlyerPage::dispatch($page->id);
        }

        $resumed = 0;
        foreach (BrochurePage::query()->where('ai_status', PageStatus::Held)->orderBy('id')->limit(200)->get() as $page) {
            if (! FlyerBudget::allowsMore()) {
                break;
            }
            FlyerPipeline::queue($page);
            $resumed++;
        }

        $checked = 0;
        foreach (Brochure::query()->where('status', BrochureStatus::Processing)->whereNotNull('ai_mode')->pluck('id') as $id) {
            FlyerPipeline::finishIfDone($id);
            $checked++;
        }

        $disk = Storage::disk(config('flyers.disk'));
        $old = collect($disk->files(config('flyers.directory').'/incoming'))
            ->filter(fn (string $file) => $disk->lastModified($file) < now()->subDay()->getTimestamp())
            ->all();
        $disk->delete($old);
        BrochureFetch::query()->where('created_at', '<', now()->subDays(90))->delete();

        return ['requeued' => $stuck->count(), 'resumed' => $resumed, 'checked' => $checked, 'deleted' => count($old)];
    }
}
