<?php

namespace App\Domain\Catalog;

use App\Domain\Offers\Offer;
use App\Domain\Search\OfferDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Makes the responsive copies of an uploaded product image with libvips:
 * {name}-{width}.avif and .webp next to the original, never upscaled, metadata stripped.
 * Then re-indexes the product's live offers so cards pick up the image.
 */
class GenerateImageSizes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const array WIDTHS = [160, 320, 480, 800, 1200];

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $imageId)
    {
        $this->onQueue('render');
    }

    public function handle(): void
    {
        $image = ProductImage::find($this->imageId);
        if (! $image) {
            return;
        }

        // Work on local temp files so the same code serves the local disk and R2.
        $disk = self::disk();
        $base = self::basePath($image->path);
        $work = sys_get_temp_dir().'/offer-image-'.$image->id.'-'.bin2hex(random_bytes(4));
        mkdir($work, 0700, true);

        try {
            $source = $work.'/original';
            file_put_contents($source, $disk->get($image->path) ?? throw new RuntimeException("Missing upload {$image->path}"));

            $size = Process::run(['vipsheader', '-f', 'width', $source]);
            $height = Process::run(['vipsheader', '-f', 'height', $source]);
            if ($size->failed()) {
                throw new RuntimeException('Not a readable image: '.$size->errorOutput());
            }

            foreach (self::WIDTHS as $width) {
                foreach (['avif' => 'Q=55,keep=none', 'webp' => 'Q=78,keep=none'] as $format => $options) {
                    $target = "{$work}/{$width}.{$format}";
                    Process::timeout(60)->run(['vips', 'thumbnail', $source, "{$target}[{$options}]", (string) $width, '--size', 'down'])->throw();
                    $disk->put("{$base}-{$width}.{$format}", file_get_contents($target), [
                        'visibility' => 'public',
                        'ContentType' => "image/{$format}",
                        'CacheControl' => 'public, max-age=31536000, immutable',
                    ]);
                }
            }
        } finally {
            array_map('unlink', glob("{$work}/*") ?: []);
            rmdir($work);
        }

        $image->forceFill(['width' => (int) trim($size->output()), 'height' => (int) trim($height->output())])->saveQuietly();

        Offer::query()->live()
            ->whereHas('variant', fn ($q) => $q->where('product_id', $image->product_id))
            ->with(OfferDocument::RELATIONS)
            ->get()
            ->searchable();
    }

    /** "products/abc.jpg" → "products/abc"; sizes are "{base}-{width}.{avif|webp}". */
    public static function basePath(string $path): string
    {
        return preg_replace('/\.[^.\/]+$/', '', $path);
    }

    /** Public URL prefix for one image's sizes; the site appends "-{width}.avif" or ".webp". */
    public static function baseUrl(string $path): string
    {
        return self::disk()->url(self::basePath($path));
    }

    /** The configured media disk: local "public" in development, "r2" in production. */
    public static function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.media_disk'));
    }
}
