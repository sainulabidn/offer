<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Flyers\Sources\BrochureSource;
use App\Domain\Flyers\Sources\FetchFailed;
use App\Domain\Flyers\Sources\ListedFlyer;
use App\Domain\Flyers\Sources\NestoOffers;
use App\Domain\Flyers\Sources\SourceFetcher;
use App\Domain\Flyers\Sources\WowDealsCatalogs;
use App\Domain\Retail\ContentPermission;
use GdImage;
use RuntimeException;

/**
 * The working folder for flyers read by a person or Claude Code instead of the flyer AI, before
 * ImportReviewedFlyer takes them in on the server:
 *
 *   export   (on the server) the stores' sources, and which flyers are already in, as JSON
 *   download (anywhere) every current flyer of those sources into one folder per flyer, with flyer.json
 *            and a manifest.json to fill; flyers already imported are skipped, by address and by file hash
 *   render   each page as page-N.jpg plus grid-N.jpg (and grid-N-top/bottom.jpg halves): a 0–1000 grid in
 *            the manifest's box units, so boxes can be read straight off the picture
 *
 * The sources are listed with the same code the automatic fetch uses (NestoOffers, WowDealsCatalogs, page links).
 */
final class ReviewedFlyerWorkspace
{
    /** Same long edge as the server renders (FlyerSettings render_long_edge default). */
    private const int EDGE = 2000;

    public function __construct(private readonly SourceFetcher $http, private readonly PdfTools $tools) {}

    /** @return array{sources: list<array>, imported_urls: list<string>, imported_hashes: list<string>} */
    public function export(bool $inactiveToo = true): array
    {
        $sources = BrochureSource::with('chain')->orderBy('chain_id')->orderBy('id')->get()
            ->filter(fn (BrochureSource $s) => ($inactiveToo || $s->is_active) && $s->chain?->content_permission === ContentPermission::Granted)
            ->map(fn (BrochureSource $s) => [
                'id' => $s->id, 'chain' => $s->chain->slug, 'chain_name' => $s->chain->name, 'name' => $s->name, 'kind' => $s->kind,
                'url' => $s->url, 'link_pattern' => $s->link_pattern, 'branch_ids' => array_map('intval', $s->branch_ids ?? []), 'is_active' => $s->is_active,
            ])->values()->all();
        $live = Brochure::query()->whereNotIn('status', [BrochureStatus::Rejected, BrochureStatus::Archived]);

        return [
            'sources' => $sources,
            'imported_urls' => (clone $live)->whereNotNull('source_url')->pluck('source_url')->unique()->values()->all(),
            'imported_hashes' => (clone $live)->whereNotNull('file_hash')->pluck('file_hash')->unique()->values()->all(),
        ];
    }

    /**
     * @param  list<int>  $only  source ids; empty = all
     * @param  callable(string): void  $log
     * @return list<string> folders of the flyers downloaded
     */
    public function download(array $export, string $dir, array $only, callable $log): array
    {
        $knownUrls = array_flip($export['imported_urls'] ?? []);
        $knownHashes = array_flip($export['imported_hashes'] ?? []);
        $done = [];
        foreach ($export['sources'] as $row) {
            if ($only !== [] && ! in_array($row['id'], $only, true)) {
                continue;
            }
            $source = new BrochureSource(['url' => $row['url'], 'kind' => $row['kind'], 'link_pattern' => $row['link_pattern']]);
            try {
                $flyers = $this->listed($source);
            } catch (FetchFailed $e) {
                $log("source {$row['id']} {$row['name']}: FAILED {$e->getMessage()}");

                continue;
            }
            $log("source {$row['id']} {$row['name']}: ".count($flyers).' listed');
            foreach ($flyers as $n => $flyer) {
                $folder = "{$dir}/{$row['id']}-".substr(md5($flyer->url), 0, 8);
                if (isset($knownUrls[$flyer->url])) {
                    $log("  skip (imported): {$flyer->url}");

                    continue;
                }
                if (is_file("{$folder}/flyer.json")) {
                    $done[] = $folder;

                    continue;
                }
                if ($flyer->validTo !== null && $flyer->validTo < now(config('app.market_timezone'))->toDateString()) {
                    continue;
                }
                try {
                    $files = $this->fetchFiles($source, $flyer, $folder);
                } catch (FetchFailed $e) {
                    $log("  FAILED {$flyer->url}: {$e->getMessage()}");
                    $this->removeDir($folder);

                    continue;
                }
                $hash = count($files) === 1 && str_ends_with($files[0], '.pdf')
                    ? hash_file('sha256', "{$folder}/{$files[0]}")
                    : hash('sha256', implode('', array_map(fn ($f) => hash_file('sha256', "{$folder}/{$f}"), $files)));
                if (isset($knownHashes[$hash])) {
                    $log("  skip (same file imported): {$flyer->url}");
                    $this->removeDir($folder);

                    continue;
                }
                $meta = [
                    'chain' => $row['chain'], 'chain_name' => $row['chain_name'], 'source_id' => $row['id'], 'source_name' => $row['name'],
                    'branch_ids' => $row['branch_ids'], 'title' => $flyer->title ?: "{$row['name']} ".now(config('app.market_timezone'))->format('j M Y'),
                    'valid_from' => $flyer->validFrom, 'valid_to' => $flyer->validTo, 'source_url' => $flyer->url, 'files' => $files, 'hash' => $hash,
                ];
                file_put_contents("{$folder}/flyer.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $knownHashes[$hash] = true;
                $done[] = $folder;
                $log('  downloaded '.count($files)." file(s): {$folder}");
            }
        }

        return $done;
    }

    /**
     * Pictures to read the flyer from, and an empty manifest.json when there is none yet.
     *
     * @return int pages
     */
    public function render(string $folder): int
    {
        $meta = json_decode((string) file_get_contents("{$folder}/flyer.json"), true, flags: JSON_THROW_ON_ERROR);
        $files = $meta['files'];
        $pages = [];
        if (count($files) === 1 && str_ends_with($files[0], '.pdf')) {
            $pdf = "{$folder}/{$files[0]}";
            $count = $this->tools->info($pdf)['pages'];
            for ($no = 1; $no <= $count; $no++) {
                $out = "{$folder}/page-{$no}.jpg";
                if (! is_file($out)) {
                    $native = $this->tools->nativeLongEdge($pdf, $no);
                    $this->tools->render($pdf, $no, $native ? max(1000, min(self::EDGE, $native)) : self::EDGE, $out);
                }
                $pages[$no] = $out;
            }
        } else {
            foreach ($files as $i => $file) {
                $out = "{$folder}/page-".($i + 1).'.jpg';
                if (! is_file($out)) {
                    $this->tools->fitImage("{$folder}/{$file}", $out, self::EDGE);
                }
                $pages[$i + 1] = $out;
            }
        }
        foreach ($pages as $no => $jpeg) {
            $this->gridded($jpeg, "{$folder}/grid-{$no}");
        }
        if (! is_file("{$folder}/manifest.json")) {
            $manifest = [
                'chain' => $meta['chain'], 'source_id' => $meta['source_id'], 'branch_ids' => $meta['branch_ids'], 'title' => $meta['title'],
                'valid_from' => $meta['valid_from'], 'valid_to' => $meta['valid_to'], 'source_url' => $meta['source_url'], 'files' => $files,
                'pages' => [],
            ];
            file_put_contents("{$folder}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return count($pages);
    }

    /** @return list<ListedFlyer> */
    private function listed(BrochureSource $source): array
    {
        return match ($source->kind) {
            BrochureSource::KIND_PDF => [new ListedFlyer($source->url)],
            BrochureSource::KIND_NESTO => (new NestoOffers)($source, $this->http),
            BrochureSource::KIND_WOWDEALS => (new WowDealsCatalogs)($source, $this->http),
            default => $this->pageLinks($source),
        };
    }

    /** @return list<ListedFlyer> */
    private function pageLinks(BrochureSource $source): array
    {
        [$response, $final] = $this->http->get($source->url);
        if (! $response->successful()) {
            throw new FetchFailed("The page answered HTTP {$response->status()}.");
        }
        $links = SourceFetcher::links($response->body(), $final, $source->link_pattern ?: BrochureSource::DEFAULT_PATTERN);
        if ($links === []) {
            throw new FetchFailed('No flyer links found on the page (it may show flyers only with JavaScript).');
        }

        return array_map(fn (string $url) => new ListedFlyer($url), array_slice($links, 0, 6));
    }

    /** @return list<string> file names in the folder */
    private function fetchFiles(BrochureSource $source, ListedFlyer $flyer, string $folder): array
    {
        @mkdir($folder, 0777, true);
        if ($source->kind === BrochureSource::KIND_WOWDEALS) {
            $urls = array_slice((new WowDealsCatalogs)->pages($flyer, $this->http), 0, 40);
            if ($urls === []) {
                throw new FetchFailed('No page pictures found.');
            }
            $files = [];
            foreach ($urls as $i => $url) {
                [$response] = $this->http->get($url);
                $type = $response->successful() ? (@getimagesizefromstring($response->body())[2] ?? null) : null;
                if (! in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                    throw new FetchFailed('Page '.($i + 1).' is not a picture.');
                }
                $files[] = $name = sprintf('upload-%02d', $i + 1).image_type_to_extension($type);
                file_put_contents("{$folder}/{$name}", $response->body());
            }

            return $files;
        }
        if (! $this->http->allowedByRobots($flyer->url)) {
            throw new FetchFailed('robots.txt asks bots not to fetch it.');
        }
        [$response] = $this->http->get($flyer->url);
        if (! $response->successful() || ! str_starts_with($response->body(), '%PDF-')) {
            throw new FetchFailed("Not a PDF (HTTP {$response->status()}).");
        }
        file_put_contents("{$folder}/flyer.pdf", $response->body());

        return ['flyer.pdf'];
    }

    /** grid.jpg with lines every 50 and labels every 100 thousandths; tall pages also as overlapping top and bottom halves. */
    private function gridded(string $jpeg, string $base): void
    {
        $image = @imagecreatefromjpeg($jpeg) ?: throw new RuntimeException("Cannot read {$jpeg}");
        $this->drawGrid($image);
        imagejpeg($image, "{$base}.jpg", 85);
        [$w, $h] = [imagesx($image), imagesy($image)];
        if ($h > $w * 1.1) {
            foreach (['top' => 0, 'bottom' => (int) ($h * 0.45)] as $part => $y) {
                $half = imagecrop($image, ['x' => 0, 'y' => $y, 'width' => $w, 'height' => (int) ($h * 0.55)]);
                imagejpeg($half, "{$base}-{$part}.jpg", 88);
            }
        } elseif ($w > $h * 1.1) {
            foreach (['left' => 0, 'right' => (int) ($w * 0.45)] as $part => $x) {
                $half = imagecrop($image, ['x' => $x, 'y' => 0, 'width' => (int) ($w * 0.55), 'height' => $h]);
                imagejpeg($half, "{$base}-{$part}.jpg", 88);
            }
        }
    }

    private function drawGrid(GdImage $image): void
    {
        [$w, $h] = [imagesx($image), imagesy($image)];
        $minor = imagecolorallocatealpha($image, 0, 160, 255, 90);
        $major = imagecolorallocatealpha($image, 255, 0, 160, 40);
        $label = imagecolorallocate($image, 255, 0, 160);
        $bg = imagecolorallocatealpha($image, 255, 255, 255, 30);
        for ($t = 50; $t < 1000; $t += 50) {
            $x = (int) round($t / 1000 * $w);
            $y = (int) round($t / 1000 * $h);
            $color = $t % 100 === 0 ? $major : $minor;
            imageline($image, $x, 0, $x, $h, $color);
            imageline($image, 0, $y, $w, $y, $color);
            if ($t % 100 === 0) {
                foreach ([0, (int) ($h / 2), $h - 18] as $ly) {
                    imagefilledrectangle($image, $x + 2, $ly, $x + 30, $ly + 16, $bg);
                    imagestring($image, 5, $x + 3, $ly, (string) $t, $label);
                }
                foreach ([0, (int) ($w / 2), $w - 34] as $lx) {
                    imagefilledrectangle($image, $lx, $y + 2, $lx + 30, $y + 18, $bg);
                    imagestring($image, 5, $lx + 1, $y + 2, (string) $t, $label);
                }
            }
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
