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

            [$width, $height] = self::hasVips() ? $this->resizeWithVips($source, $work) : $this->resizeWithGd($source, $work);

            foreach (self::WIDTHS as $size) {
                foreach (['avif', 'webp'] as $format) {
                    $disk->put("{$base}-{$size}.{$format}", file_get_contents("{$work}/{$size}.{$format}"), [
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

        $image->forceFill(['width' => $width, 'height' => $height])->saveQuietly();

        Offer::query()->live()
            ->whereHas('variant', fn ($q) => $q->where('product_id', $image->product_id))
            ->with(OfferDocument::RELATIONS)
            ->get()
            ->searchable();
    }

    /** libvips is faster and strips metadata; servers without it (AlmaLinux 8) use PHP's GD. */
    public static function hasVips(): bool
    {
        return match (config('filesystems.image_tool')) {
            'vips' => true,
            'gd' => false,
            default => Process::run(['sh', '-c', 'command -v vips'])->successful(),
        };
    }

    /** @return array{int, int} original width and height */
    private function resizeWithVips(string $source, string $work): array
    {
        $width = Process::run(['vipsheader', '-f', 'width', $source]);
        $height = Process::run(['vipsheader', '-f', 'height', $source]);
        if ($width->failed()) {
            throw new RuntimeException('Not a readable image: '.$width->errorOutput());
        }

        foreach (self::WIDTHS as $size) {
            foreach (['avif' => 'Q=55,keep=none', 'webp' => 'Q=78,keep=none'] as $format => $options) {
                Process::timeout(60)->run(['vips', 'thumbnail', $source, "{$work}/{$size}.{$format}[{$options}]", (string) $size, '--size', 'down'])->throw();
            }
        }

        return [(int) trim($width->output()), (int) trim($height->output())];
    }

    /** Same files with GD (no metadata is copied: GD re-encodes pixels only). @return array{int, int} */
    private function resizeWithGd(string $source, string $work): array
    {
        $original = @imagecreatefromstring((string) file_get_contents($source));
        if (! $original) {
            throw new RuntimeException('Not a readable image (JPG, PNG or WebP expected).');
        }
        $width = imagesx($original);
        $height = imagesy($original);

        foreach (self::WIDTHS as $size) {
            // Never upscale: small originals keep their own size.
            $resized = $size < $width ? imagescale($original, $size, -1, IMG_BICUBIC) : $original;
            imagesavealpha($resized, true);
            imageavif($resized, "{$work}/{$size}.avif", 55, 6) || throw new RuntimeException('GD could not write AVIF.');
            imagewebp($resized, "{$work}/{$size}.webp", 78) || throw new RuntimeException('GD could not write WebP.');
            if ($resized !== $original) {
                imagedestroy($resized);
            }
        }
        imagedestroy($original);

        return [$width, $height];
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
