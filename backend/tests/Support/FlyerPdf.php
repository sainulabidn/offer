<?php

namespace Tests\Support;

/**
 * Tiny real PDFs for flyer tests, written by hand so poppler renders them like the real thing:
 * picture-only pages (most retailer flyers) and pages with selectable text.
 */
final class FlyerPdf
{
    /** One JPEG picture per page, no text layer. @param list<string> $labels drawn on each page so pages differ */
    public static function pictures(array $labels): string
    {
        return self::build(array_map(fn (string $label) => ['jpeg' => self::jpeg($label)], $labels));
    }

    /** Pages of selectable text. @param list<list<string>> $pages lines per page */
    public static function text(array $pages): string
    {
        return self::build(array_map(fn (array $lines) => ['lines' => $lines], $pages));
    }

    /** A flyer-like picture: white page, a few dark bars and the label. */
    public static function jpeg(string $label, int $width = 620, int $height = 877): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 20, 20, 20);
        imagestring($image, 5, 40, 40, $label, $ink);
        foreach ([150, 400, 650] as $i => $y) {
            imagefilledrectangle($image, 40, $y, 40 + 120 * ($i + 1), $y + 60, imagecolorallocate($image, 200, 30 * $i, 40));
        }
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    /** @param  list<array{jpeg?: string, lines?: list<string>}>  $pages */
    private static function build(array $pages): string
    {
        // 1 catalog, 2 page tree, 3 font; then per page: page, contents, picture.
        $objects = [1 => '', 2 => '', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $kids = [];
        foreach ($pages as $page) {
            $pageId = count($objects) + 1;
            $contentId = $pageId + 1;
            $imageId = $pageId + 2;
            $kids[] = "{$pageId} 0 R";
            if (isset($page['jpeg'])) {
                [$w, $h] = getimagesizefromstring($page['jpeg']);
                $content = 'q 595 0 0 842 0 0 cm /Im1 Do Q';
                $resources = "<< /XObject << /Im1 {$imageId} 0 R >> >>";
            } else {
                $text = collect($page['lines'])->map(fn (string $line) => '('.addcslashes($line, '()\\').') Tj 0 -28 Td')->implode(' ');
                $content = "BT /F1 18 Tf 50 780 Td {$text} ET";
                $resources = '<< /Font << /F1 3 0 R >> >>';
            }
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources {$resources} /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
            $objects[$imageId] = isset($page['jpeg'])
                ? "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($page['jpeg'])." >>\nstream\n{$page['jpeg']}\nendstream"
                : '<< >>';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
