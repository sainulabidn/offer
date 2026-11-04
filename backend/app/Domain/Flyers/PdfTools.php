<?php

namespace App\Domain\Flyers;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The command-line tools that turn a flyer into page pictures: poppler (pdfinfo, pdfimages, pdftoppm,
 * pdftotext) and libvips, with GD when vips is missing. Arguments are passed as arrays (no shell) and every
 * command has a timeout (config flyers.process_timeout). Paths are local files.
 */
final class PdfTools
{
    /** @return array{pages: int, encrypted: bool} */
    public function info(string $pdf): array
    {
        $out = $this->run(['pdfinfo', $pdf]);
        if (! preg_match('/^Pages:\s+(\d+)/m', $out, $pages)) {
            throw new RuntimeException('This PDF has no pages, or is damaged.');
        }

        return ['pages' => (int) $pages[1], 'encrypted' => (bool) preg_match('/^Encrypted:\s+yes/m', $out)];
    }

    /**
     * The long edge in pixels at which this page shows its pictures at their own resolution, so the render
     * never invents detail. Null for pages without pictures (vector text and shapes render sharp at any size).
     */
    public function nativeLongEdge(string $pdf, int $page): ?int
    {
        $info = $this->run(['pdfinfo', '-f', (string) $page, '-l', (string) $page, $pdf]);
        if (! preg_match('/^Page\s+\d+\s+size:\s+([\d.]+)\s+x\s+([\d.]+)\s+pts/m', $info, $size)) {
            return null;
        }

        // pdfimages -list: page num type width height color comp bpc enc interp object ID x-ppi y-ppi size ratio
        $largest = null;
        foreach (explode("\n", $this->run(['pdfimages', '-list', '-f', (string) $page, '-l', (string) $page, $pdf])) as $line) {
            $cols = preg_split('/\s+/', trim($line));
            if (count($cols) < 14 || ! ctype_digit($cols[0]) || $cols[2] !== 'image') {
                continue;
            }
            $area = (int) $cols[3] * (int) $cols[4];
            if ($largest === null || $area > $largest['area']) {
                $largest = ['area' => $area, 'ppi' => max((int) $cols[12], (int) $cols[13])];
            }
        }
        if ($largest === null || $largest['ppi'] <= 0) {
            return null;
        }

        return (int) round(max((float) $size[1], (float) $size[2]) / 72 * $largest['ppi']);
    }

    /** Renders one page as a JPEG whose long edge is $longEdge pixels. */
    public function render(string $pdf, int $page, int $longEdge, string $jpeg): void
    {
        $base = preg_replace('/\.jpe?g$/', '', $jpeg);
        $this->run(['pdftoppm', '-f', (string) $page, '-l', (string) $page, '-scale-to', (string) $longEdge, '-jpeg', '-jpegopt', 'quality=85', '-singlefile', $pdf, $base]);
        if ("{$base}.jpg" !== $jpeg) {
            rename("{$base}.jpg", $jpeg);
        }
    }

    /** The page's own text, or null when it has none worth sending (most flyers are pictures). */
    public function text(string $pdf, int $page): ?string
    {
        $text = trim($this->run(['pdftotext', '-layout', '-enc', 'UTF-8', '-f', (string) $page, '-l', (string) $page, $pdf, '-']));

        return preg_match_all('/\p{L}{2,}/u', $text) >= 5 ? $text : null;
    }

    /** A JPEG no larger than $longEdge on its long edge (photos of flyers; never enlarged). */
    public function fitImage(string $source, string $jpeg, int $longEdge): void
    {
        if ($this->hasVips()) {
            $this->run(['vipsthumbnail', $source, '--size', "{$longEdge}x{$longEdge}>", '-o', "{$jpeg}[Q=85,strip]"]);

            return;
        }
        $this->gdResize($source, $jpeg, $longEdge, $longEdge, 85);
    }

    /** A small JPEG for the review screen's page strip. */
    public function thumbnail(string $source, string $jpeg, int $width = 240): void
    {
        if ($this->hasVips()) {
            $this->run(['vipsthumbnail', $source, '--size', "{$width}x", '-o', "{$jpeg}[Q=75,strip]"]);

            return;
        }
        $this->gdResize($source, $jpeg, $width, PHP_INT_MAX, 75);
    }

    private function hasVips(): bool
    {
        static $has = null;

        return $has ??= Process::run(['vipsthumbnail', '--version'])->successful();
    }

    private function gdResize(string $source, string $jpeg, int $maxWidth, int $maxHeight, int $quality): void
    {
        $image = @imagecreatefromstring((string) file_get_contents($source)) ?: throw new RuntimeException('Not a readable image (JPG, PNG or WebP expected).');
        $scale = min(1, $maxWidth / imagesx($image), $maxHeight / imagesy($image));
        if ($scale < 1) {
            $image = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)), IMG_BICUBIC);
        }
        imagejpeg($image, $jpeg, $quality) || throw new RuntimeException('GD could not write the JPEG.');
    }

    /** @param  list<string>  $command */
    private function run(array $command): string
    {
        $result = Process::timeout((int) config('flyers.process_timeout'))->run($command);
        if (! $result->successful()) {
            throw new RuntimeException("{$command[0]} failed: ".trim($result->errorOutput() ?: $result->output()));
        }

        return $result->output();
    }
}
