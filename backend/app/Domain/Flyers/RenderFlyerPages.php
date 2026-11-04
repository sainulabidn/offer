<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochurePage;
use App\Domain\Brochures\PageStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Renders some pages of a flyer to the JPEGs the AI and the review screen use: at the flyer's own
 * resolution, capped at FlyerSettings render_long_edge; keeps the PDF's own text when it has any, and a
 * hash of the picture to recognise the page in later editions. Then routes each page (FlyerPipeline).
 */
class RenderFlyerPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Below this a page's small print gets hard to read, whatever the file's own resolution. */
    private const int MIN_LONG_EDGE = 1000;

    public int $tries = 3;

    public int $timeout = 55;

    /** @param  list<int>  $pageNumbers */
    public function __construct(public int $brochureId, public array $pageNumbers)
    {
        $this->onQueue('render');
    }

    public function handle(PdfTools $tools): void
    {
        $brochure = Brochure::find($this->brochureId);
        if (! $brochure) {
            return;
        }
        $disk = Storage::disk(config('flyers.disk'));
        $folder = config('flyers.directory')."/{$brochure->id}";
        $setting = (int) FlyerSettings::get('render_long_edge');

        $pages = $brochure->pages()->whereIn('page_no', $this->pageNumbers)->where('ai_status', PageStatus::Pending)->get();
        foreach ($pages as $page) {
            try {
                $image = "{$folder}/page-{$page->page_no}.jpg";
                if ($brochure->type === 'pdf') {
                    $pdf = $disk->path($brochure->original_path);
                    $native = $tools->nativeLongEdge($pdf, $page->page_no);
                    $edge = $native ? max(self::MIN_LONG_EDGE, min($setting, $native)) : $setting;
                    $tools->render($pdf, $page->page_no, $edge, $disk->path($image));
                    $text = $tools->text($pdf, $page->page_no);
                } else {
                    $tools->fitImage($disk->path($page->image_path), $disk->path($image), $setting);
                    $text = null;
                }
                $thumb = "{$folder}/thumb-{$page->page_no}.jpg";
                $tools->thumbnail($disk->path($image), $disk->path($thumb));
                [$width, $height] = getimagesize($disk->path($image));

                $page->update([
                    'image_path' => $image,
                    'thumb_path' => $thumb,
                    'width' => $width,
                    'height' => $height,
                    'text_layer' => $text !== null ? ['text' => $text] : null,
                    'content_hash' => hash_file('sha256', $disk->path($image)),
                ]);
                FlyerPipeline::route($page);
            } catch (Throwable $e) {
                report($e);
                $page->update(['ai_status' => PageStatus::Failed, 'ai_error' => 'The page could not be rendered: '.$e->getMessage()]);
            }
        }

        FlyerPipeline::finishIfDone($brochure->id);
    }

    /** After the last try, pages it never got to (timeout, crash) are marked failed, so the flyer can finish. */
    public function failed(Throwable $e): void
    {
        BrochurePage::query()->where('brochure_id', $this->brochureId)->whereIn('page_no', $this->pageNumbers)
            ->where('ai_status', PageStatus::Pending)
            ->update(['ai_status' => PageStatus::Failed, 'ai_error' => 'The page could not be rendered: '.$e->getMessage()]);
        FlyerPipeline::finishIfDone($this->brochureId);
    }
}
