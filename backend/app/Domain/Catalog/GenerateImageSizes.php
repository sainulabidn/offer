<?php

namespace App\Domain\Catalog;

use App\Domain\Offers\Offer;
use App\Domain\Search\OfferDocument;
use GdImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Turns an uploaded product photo into the square copies the website shows: the product trimmed of its plain
 * border and centred on white with an even margin, as {base}-350.webp and {base}-160.webp. Nothing larger is
 * kept: the 350 px copy replaces the upload. The website lays them on a light gradient with multiply, so the
 * white falls away. Then re-indexes the product's live offers so cards pick up the image.
 */
class GenerateImageSizes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int SIZE = 350;

    public const array WIDTHS = [160, self::SIZE];

    /** Sizes earlier versions made (AVIF and WebP), removed when an image is redone or deleted. */
    private const array OLD_WIDTHS = [160, 320, 480, 800, 1200];

    /** Space around the product, as a share of the square. */
    private const float MARGIN = 0.06;

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

        $disk = self::disk();
        $base = self::basePath($image->path);
        $master = "{$base}-".self::SIZE.'.webp';
        // A second job for the same upload finds it done; squaring the square again would only lose quality.
        if ($image->path === $master && $image->width === self::SIZE) {
            return;
        }
        $square = self::square($disk->get($image->path) ?? throw new RuntimeException("Missing upload {$image->path}"));

        foreach (self::WIDTHS as $size) {
            $copy = $size === self::SIZE ? $square : imagescale($square, $size, $size, IMG_BICUBIC);
            ob_start();
            imagewebp($copy, null, 82) || throw new RuntimeException('GD could not write WebP.');
            $disk->put("{$base}-{$size}.webp", (string) ob_get_clean(), [
                'visibility' => 'public',
                'ContentType' => 'image/webp',
                'CacheControl' => 'public, max-age=31536000, immutable',
            ]);
        }

        if ($image->path !== $master) {
            $disk->delete([$image->path, ...self::oldCopies($base)]);
        }
        $image->forceFill(['path' => $master, 'width' => self::SIZE, 'height' => self::SIZE])->saveQuietly();

        Offer::query()->live()
            ->whereHas('variant', fn ($q) => $q->where('product_id', $image->product_id))
            ->with(OfferDocument::RELATIONS)
            ->get()
            ->searchable();
    }

    /** The product on a white square, trimmed and scaled so every photo fills its tile the same way. */
    private static function square(string $bytes): GdImage
    {
        $photo = @imagecreatefromstring($bytes) ?: throw new RuntimeException('Not a readable image (JPG, PNG or WebP expected).');
        imagepalettetotruecolor($photo);
        $photo = self::trim($photo);

        $width = imagesx($photo);
        $height = imagesy($photo);
        $scale = self::SIZE * (1 - 2 * self::MARGIN) / max($width, $height);
        $fitWidth = max(1, (int) round($width * $scale));
        $fitHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true); // a cut-out PNG lands on the white
        imagecopyresampled($canvas, $photo, intdiv(self::SIZE - $fitWidth, 2), intdiv(self::SIZE - $fitHeight, 2), 0, 0, $fitWidth, $fitHeight, $width, $height);

        return $canvas;
    }

    /** Cuts a transparent or near-white border; a photo with a real background is left whole. */
    private static function trim(GdImage $photo): GdImage
    {
        $corner = imagecolorsforindex($photo, imagecolorat($photo, 0, 0));
        $trimmed = match (true) {
            $corner['alpha'] > 100 => imagecropauto($photo, IMG_CROP_TRANSPARENT),
            min($corner['red'], $corner['green'], $corner['blue']) > 235 => imagecropauto($photo, IMG_CROP_THRESHOLD, 2, 0xFFFFFF),
            default => false,
        };

        return $trimmed ?: $photo;
    }

    /** @return list<string> */
    private static function oldCopies(string $base): array
    {
        return collect(self::OLD_WIDTHS)
            ->flatMap(fn (int $w) => ["{$base}-{$w}.avif", "{$base}-{$w}.webp"])
            ->reject(fn (string $file) => in_array($file, self::files($base), true))
            ->values()->all();
    }

    /** Every file one image may have on the disk, current and from earlier versions. @return list<string> */
    public static function allFiles(string $path): array
    {
        $base = self::basePath($path);

        return array_values(array_unique([$path, ...self::files($base), ...self::oldCopies($base)]));
    }

    /** @return list<string> */
    private static function files(string $base): array
    {
        return array_map(fn (int $w) => "{$base}-{$w}.webp", self::WIDTHS);
    }

    /** "products/abc.jpg" and its square copy "products/abc-350.webp" → "products/abc"; sizes are "{base}-{width}.webp". */
    public static function basePath(string $path): string
    {
        return preg_replace('/(-'.self::SIZE.')?\.[^.\/]+$/', '', $path);
    }

    /** Public URL prefix for one image's sizes; the site appends "-160.webp" or "-350.webp". */
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
