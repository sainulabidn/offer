<?php

namespace App\Domain\Seo;

use GdImage;
use RuntimeException;

/**
 * A 1200 × 630 picture for social sharing (Open Graph), drawn with GD in the site's font (Figtree,
 * resources/fonts). Sizes are in pixels; GD's own point sizes are converted here.
 */
final class ShareCard
{
    public const int WIDTH = 1200;

    public const int HEIGHT = 630;

    /** WhatsApp skips previews of large pictures; a JPEG of a card stays well under 300 KB. */
    private const int QUALITY = 86;

    /** GD renders TrueType at 96 dpi: 1 pt is 4/3 px. */
    private const float PT_PER_PX = 0.75;

    private GdImage $image;

    /** @var array<string, int> */
    private array $colors = [];

    public function __construct(string $background)
    {
        $this->image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($this->image, true);
        imagefilledrectangle($this->image, 0, 0, self::WIDTH, self::HEIGHT, $this->color($background));
    }

    public function color(string $hex): int
    {
        return $this->colors[$hex] ??= imagecolorallocate($this->image, ...sscanf(ltrim($hex, '#'), '%02x%02x%02x'));
    }

    private static function font(string $weight): string
    {
        return resource_path("fonts/Figtree-{$weight}.ttf");
    }

    /** A filled rectangle with round corners. */
    public function box(int $x, int $y, int $width, int $height, string $hex, int $radius = 0): void
    {
        $color = $this->color($hex);
        $radius = min($radius, intdiv(min($width, $height), 2));
        imagefilledrectangle($this->image, $x + $radius, $y, $x + $width - $radius - 1, $y + $height - 1, $color);
        imagefilledrectangle($this->image, $x, $y + $radius, $x + $width - 1, $y + $height - $radius - 1, $color);
        if ($radius > 0) {
            foreach ([[$x + $radius, $y + $radius], [$x + $width - $radius - 1, $y + $radius], [$x + $radius, $y + $height - $radius - 1], [$x + $width - $radius - 1, $y + $height - $radius - 1]] as [$cx, $cy]) {
                imagefilledellipse($this->image, $cx, $cy, $radius * 2, $radius * 2, $color);
            }
        }
    }

    /** Width of a line of text in pixels. */
    public function width(string $text, int $size, string $weight): int
    {
        $box = imagettfbbox($size * self::PT_PER_PX, 0, self::font($weight), $text) ?: throw new RuntimeException('Font not readable.');

        return $box[2] - $box[0];
    }

    /** Writes one line with its baseline at $y; returns its width. */
    public function text(string $text, int $x, int $y, int $size, string $weight, string $hex): int
    {
        imagettftext($this->image, $size * self::PT_PER_PX, 0, $x, $y, $this->color($hex), self::font($weight), $text);

        return $this->width($text, $size, $weight);
    }

    /**
     * Splits text into lines no wider than $maxWidth; past $maxLines the last line ends in "…".
     *
     * @return list<string>
     */
    public function wrap(string $text, int $size, string $weight, int $maxWidth, int $maxLines): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = $line === '' ? $word : "{$line} {$word}";
            if ($line !== '' && $this->width($candidate, $size, $weight) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        if (count($lines) <= $maxLines) {
            return $lines;
        }

        $kept = array_slice($lines, 0, $maxLines);
        $last = $kept[$maxLines - 1];
        while ($last !== '' && $this->width("{$last}…", $size, $weight) > $maxWidth) {
            $last = preg_replace('/\s*\S+$/u', '', $last);
        }
        $kept[$maxLines - 1] = "{$last}…";

        return $kept;
    }

    /** A product photo scaled into a square; false when the bytes aren't a picture. */
    public function photo(string $bytes, int $x, int $y, int $size): bool
    {
        $photo = @imagecreatefromstring($bytes);
        if (! $photo) {
            return false;
        }
        imagecopyresampled($this->image, $photo, $x, $y, 0, 0, $size, $size, imagesx($photo), imagesy($photo));

        return true;
    }

    /** A horizontal line, e.g. to strike through an old price. */
    public function line(int $x1, int $x2, int $y, string $hex, int $thickness = 2): void
    {
        imagesetthickness($this->image, $thickness);
        imageline($this->image, $x1, $y, $x2, $y, $this->color($hex));
        imagesetthickness($this->image, 1);
    }

    public function jpeg(): string
    {
        ob_start();
        imagejpeg($this->image, null, self::QUALITY);

        return (string) ob_get_clean();
    }
}
