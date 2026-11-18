<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureItem;
use App\Domain\Brochures\BrochurePage;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\ItemStatus;
use App\Domain\Brochures\PageStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Catalog\CatalogRowImporter;
use App\Domain\Catalog\Category;
use App\Domain\Catalog\GenerateImageSizes;
use App\Domain\Catalog\PackSize;
use App\Domain\Catalog\Product;
use App\Domain\Catalog\ProductImage;
use App\Domain\Catalog\ProductVariant;
use App\Domain\Flyers\Ai\FlyerSchema;
use App\Domain\Flyers\Ai\PageResult;
use App\Domain\Flyers\Ai\TokenUsage;
use App\Domain\Flyers\Sources\BrochureSource;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Retail\Chain;
use App\Domain\Search\OfferDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Imports a flyer whose offers were already read and checked by a person (or by Claude Code on their
 * machine), so no flyer AI is called and nothing is queued: the manifest carries each page's items in
 * FlyerSchema's shape, plus where a product that isn't in the catalog yet belongs.
 *
 * The usual steps run on that answer — ItemNormalizer, VariantMatcher, AcceptItems, PublishBrochure — so the
 * result is the same as a flyer read by the pipeline and accepted on the review screen. Items with no
 * catalog match become products (CatalogRowImporter, creating the category path, group and brand when new),
 * and a product without a photo gets one cropped from the flyer at the item's box.
 *
 * Manifest (JSON; file paths relative to it):
 *   chain        chain id or slug                       branch_ids  [] = every branch (optional)
 *   source_id    brochure_sources.id (optional)         title, valid_from, valid_to (YYYY-MM-DD), source_url
 *   files        ["flyer.pdf"] or page pictures in order
 *   pages        [{page: 1, type: "offers", from, until, where: [], items: [FlyerSchema item + hints]}]
 * Item hints: product (catalog name, default name), category ("Parent > Child", created when missing),
 * group (product group, created when missing), variant_id (forces the pack), photo (false = no crop).
 */
final class ImportReviewedFlyer
{
    /** Extra space around an item's box when cropping its photo, as a share of the box. */
    private const float CROP_PAD = 0.03;

    public const string SOURCE = 'reviewed_import';

    /** Suffix of the sibling group made when a group already compares another kind of unit. */
    private const array DIMENSION_LABELS = ['mass' => 'by weight', 'volume' => 'by volume', 'count' => 'packs', 'length' => 'by length', 'sheets' => 'by sheet', 'washes' => 'by wash'];

    public function __construct(
        private readonly PdfTools $tools,
        private readonly ApplyPageResult $apply,
        private readonly CatalogRowImporter $catalog,
        private readonly AcceptItems $accept,
        private readonly PublishBrochure $publish,
    ) {}

    /**
     * @param  callable(string): void  $log
     * @return array{brochure: ?Brochure, skipped: ?string, items: int, accepted: int, created: int, photos: int, published: int, problems: list<string>}
     */
    public function __invoke(string $manifestPath, ?User $user, callable $log, bool $publish = true, bool $retry = false): array
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $dir = dirname(realpath($manifestPath));
        $chain = is_numeric($manifest['chain'] ?? null)
            ? Chain::find((int) $manifest['chain'])
            : Chain::where('slug', (string) ($manifest['chain'] ?? ''))->first();
        $chain ?? throw new InvalidArgumentException('Unknown chain ['.($manifest['chain'] ?? '').'].');
        $files = array_map(fn (string $f) => str_starts_with($f, '/') ? $f : "{$dir}/{$f}", (array) ($manifest['files'] ?? []));
        if ($files === [] || array_filter($files, fn ($f) => ! is_file($f))) {
            throw new InvalidArgumentException('Manifest files are missing: '.implode(', ', array_filter($files, fn ($f) => ! is_file($f))));
        }

        $stats = ['brochure' => null, 'skipped' => null, 'items' => 0, 'accepted' => 0, 'created' => 0, 'photos' => 0, 'published' => 0, 'problems' => []];
        $isPdf = count($files) === 1 && str_starts_with((string) file_get_contents($files[0], length: 5), '%PDF-');
        $hash = $isPdf ? hash_file('sha256', $files[0]) : hash('sha256', implode('', array_map('hash_file', array_fill(0, count($files), 'sha256'), $files)));
        $existing = Brochure::where('chain_id', $chain->id)->where('file_hash', $hash)
            ->whereNotIn('status', [BrochureStatus::Rejected, BrochureStatus::Archived])->first();
        if ($existing && $retry) {
            return $this->retry($existing, $manifest, $user, $publish, $stats);
        }
        if ($existing) {
            $stats['skipped'] = "already imported as brochure {$existing->id} ({$existing->status->value}); --retry places the items not accepted yet";
            $stats['brochure'] = $existing;

            return $stats;
        }

        $brochure = $this->createBrochure($manifest, $chain, $files, $isPdf, $hash, $user);
        $stats['brochure'] = $brochure;
        $log("Brochure {$brochure->id}: {$brochure->page_count} pages");
        $this->render($brochure);

        $byNo = collect($manifest['pages'] ?? [])->keyBy(fn ($p) => (int) ($p['page'] ?? 0));
        foreach ($brochure->pages()->orderBy('page_no')->get() as $page) {
            $data = $byNo->get($page->page_no);
            if ($page->ai_status === PageStatus::Failed) {
                $stats['problems'][] = "page {$page->page_no}: {$page->ai_error}";

                continue;
            }
            if ($data === null) {
                $page->update(['ai_status' => PageStatus::Skipped, 'page_type' => 'info']);

                continue;
            }
            $this->importPage($brochure, $page, $data, $user, $stats);
        }

        $brochure->update(['status' => BrochureStatus::InReview, 'reviewed_by' => $user?->id]);
        if ($publish && $brochure->offers()->exists()) {
            $stats['published'] = ($this->publish)($brochure->refresh());
        }

        return $stats;
    }

    private function createBrochure(array $manifest, Chain $chain, array $files, bool $isPdf, string $hash, ?User $user): Brochure
    {
        $disk = Storage::disk(config('flyers.disk'));
        $today = now(config('app.market_timezone'));
        $title = trim((string) ($manifest['title'] ?? '')) ?: "{$chain->name} flyer {$today->format('j M Y')}";
        $from = $manifest['valid_from'] ?? null;
        $to = $manifest['valid_to'] ?? null;
        $source = isset($manifest['source_id']) ? BrochureSource::find((int) $manifest['source_id']) : null;
        $pages = $isPdf ? $this->tools->info($files[0])['pages'] : count($files);

        return DB::transaction(function () use ($manifest, $chain, $files, $isPdf, $hash, $user, $disk, $today, $title, $from, $to, $source, $pages) {
            $brochure = new Brochure([
                'chain_id' => $chain->id,
                'type' => $isPdf ? 'pdf' : 'images',
                'title' => ['en' => $title],
                'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
                'valid_from' => $from ?: $today->toDateString(),
                'valid_to' => $to ?: $today->copy()->addDays(6)->toDateString(),
                'status' => BrochureStatus::Processing,
                'source' => self::SOURCE,
                'source_url' => $manifest['source_url'] ?? null,
                'file_hash' => $hash,
                'page_count' => $pages,
                'ai_mode' => 'fast',
                'detected' => ['dates_given' => filled($from) && filled($to)],
            ]);
            $brochure->created_by = $user?->id;
            $brochure->brochure_source_id = $source?->id;
            $brochure->save();
            $branchIds = array_map('intval', $manifest['branch_ids'] ?? $source?->branch_ids ?? []);
            foreach (array_unique($branchIds) as $branchId) {
                $brochure->scopes()->create(['scope_type' => 'branch', 'scope_id' => $branchId]);
            }

            $folder = config('flyers.directory')."/{$brochure->id}";
            if ($isPdf) {
                $disk->put("{$folder}/flyer.pdf", file_get_contents($files[0]));
                $brochure->update(['original_path' => "{$folder}/flyer.pdf"]);
                for ($no = 1; $no <= $pages; $no++) {
                    $brochure->pages()->create(['page_no' => $no, 'ai_status' => PageStatus::Pending]);
                }
            } else {
                foreach ($files as $i => $file) {
                    $path = "{$folder}/upload-".($i + 1).'.'.(pathinfo($file, PATHINFO_EXTENSION) ?: 'jpg');
                    $disk->put($path, file_get_contents($file));
                    $brochure->pages()->create(['page_no' => $i + 1, 'image_path' => $path, 'ai_status' => PageStatus::Pending]);
                }
                $brochure->update(['original_path' => $folder]);
            }

            return $brochure;
        });
    }

    /** RenderFlyerPages without routing to the AI. */
    private function render(Brochure $brochure): void
    {
        $disk = Storage::disk(config('flyers.disk'));
        $folder = config('flyers.directory')."/{$brochure->id}";
        $edge = (int) FlyerSettings::get('render_long_edge');
        foreach ($brochure->pages()->orderBy('page_no')->get() as $page) {
            try {
                $image = "{$folder}/page-{$page->page_no}.jpg";
                $text = null;
                if ($brochure->type === 'pdf') {
                    $pdf = $disk->path($brochure->original_path);
                    $native = $this->tools->nativeLongEdge($pdf, $page->page_no);
                    $this->tools->render($pdf, $page->page_no, $native ? max(1000, min($edge, $native)) : $edge, $disk->path($image));
                    $text = $this->tools->text($pdf, $page->page_no);
                } else {
                    $this->tools->fitImage($disk->path($page->image_path), $disk->path($image), $edge);
                }
                $thumb = "{$folder}/thumb-{$page->page_no}.jpg";
                $this->tools->thumbnail($disk->path($image), $disk->path($thumb));
                [$width, $height] = getimagesize($disk->path($image));
                $page->update([
                    'image_path' => $image, 'thumb_path' => $thumb, 'width' => $width, 'height' => $height,
                    'text_layer' => $text !== null ? ['text' => $text] : null,
                    'content_hash' => hash_file('sha256', $disk->path($image)),
                ]);
            } catch (Throwable $e) {
                $page->update(['ai_status' => PageStatus::Failed, 'ai_error' => 'The page could not be rendered: '.$e->getMessage()]);
            }
        }
    }

    private function importPage(Brochure $brochure, BrochurePage $page, array $data, ?User $user, array &$stats): void
    {
        // The same filter as PageResult::fromAnswer, so item positions line up with the hints.
        $items = array_values(array_filter((array) ($data['items'] ?? []), fn ($i) => is_array($i)
            && trim((string) ($i['name'] ?? '')) !== '' && is_numeric($i['price'] ?? null) && (float) $i['price'] > 0));
        $answer = [
            'page' => $data['type'] ?? 'offers', 'from' => $data['from'] ?? $brochure->valid_from->toDateString(),
            'until' => $data['until'] ?? $brochure->valid_to->toDateString(), 'where' => $data['where'] ?? [],
            'items' => array_map(fn ($i) => array_intersect_key($i, array_flip(FlyerSchema::ITEM_FIELDS)), $items),
        ];
        $result = PageResult::fromAnswer($answer, new TokenUsage, 'claude-code');
        $page->update(['ai_output' => $result->toArray(), 'ai_status' => PageStatus::Done, 'ai_error' => null, 'extracted_at' => now(), 'ai_attempts' => 1]);
        ($this->apply)($page, $result);

        $this->placeItems($page, $items, $user, $stats);
    }

    /**
     * An imported flyer again: its items not accepted yet (a missing category hint, a unit the group didn't take…)
     * are placed from the manifest's corrected hints and published. Accepted items are left alone.
     */
    private function retry(Brochure $brochure, array $manifest, ?User $user, bool $publish, array $stats): array
    {
        $stats['brochure'] = $brochure;
        $byNo = collect($manifest['pages'] ?? [])->keyBy(fn ($p) => (int) ($p['page'] ?? 0));
        foreach ($brochure->pages()->orderBy('page_no')->get() as $page) {
            if (($data = $byNo->get($page->page_no)) === null) {
                continue;
            }
            $items = array_values(array_filter((array) ($data['items'] ?? []), fn ($i) => is_array($i)
                && trim((string) ($i['name'] ?? '')) !== '' && is_numeric($i['price'] ?? null) && (float) $i['price'] > 0));
            $this->placeItems($page, $items, $user, $stats, onlyOpen: true);
        }
        $pending = $brochure->offers()->whereIn('status', [OfferStatus::Draft, OfferStatus::Approved])->get();
        if ($publish && $pending->isNotEmpty()) {
            $stats['published'] = $this->publish->publishOffers($brochure, $pending);
            Offer::syncSearch(Offer::query()->whereKey($pending->modelKeys())->with(OfferDocument::RELATIONS)->get());
        }

        return $stats;
    }

    /** @param  list<array>  $items  the page's manifest items, by position */
    private function placeItems(BrochurePage $page, array $items, ?User $user, array &$stats, bool $onlyOpen = false): void
    {
        foreach ($page->items()->orderBy('position')->get() as $item) {
            if ($onlyOpen && $item->status === ItemStatus::Accepted) {
                continue;
            }
            $hint = $items[$item->position] ?? [];
            $label = "p{$page->page_no} #{$item->position} {$item->name}";
            $stats['items']++;
            try {
                if (is_numeric($hint['variant_id'] ?? null) && ProductVariant::whereKey((int) $hint['variant_id'])->exists()) {
                    $item->update(['product_variant_id' => (int) $hint['variant_id'], 'match_method' => 'manual']);
                } elseif ($item->match_method === 'name' && ! $this->sameProduct($item, $hint)) {
                    $item->update(['product_variant_id' => null, 'match_method' => null]);
                }
                if ($item->product_variant_id === null) {
                    $stats['created'] += $this->createProduct($item, $hint) ? 1 : 0;
                }
                $item->refresh();
                if ($item->regular_price !== null && (float) $item->regular_price <= (float) $item->price) {
                    $item->update(['regular_price' => null]); // a "was" not above the price is a misread
                }
                if (($hint['photo'] ?? true) && $this->addPhoto($item, $page)) {
                    $stats['photos']++;
                }
                $issues = collect($item->issues ?? [])->pluck('code')->reject(fn ($c) => in_array($c, ['product', 'was', 'unsure'], true));
                if ($issues->isNotEmpty()) {
                    $stats['problems'][] = "{$label}: check ".$issues->implode(', ');
                }
                $this->accept->accept($item, $user);
                $item->update(['issues' => []]);
                $stats['accepted']++;
            } catch (InvalidArgumentException|ValidationException $e) {
                $why = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
                $stats['problems'][] = "{$label}: {$why}";
            }
        }
    }

    /** @return bool whether the product is new (a product of the same name gets the pack instead) */
    private function createProduct(BrochureItem $item, array $hint): bool
    {
        $name = trim((string) ($hint['product'] ?? '')) ?: $item->name;
        $isNew = ! Product::where('slug', Str::slug($name))->exists();
        $size = PackSize::parse($item->size_text) ?? PackSize::parse('1 pc');
        $category = $this->category((string) ($hint['category'] ?? '')
            ?: throw new InvalidArgumentException('no catalog match and no category hint'));
        $group = trim((string) ($hint['group'] ?? '')) ?: $category->getTranslation('name', 'en');
        $row = [
            'category' => $category->slug,
            'product_group' => $group,
            'brand' => $item->brand_text,
            'product_name' => $name,
            'product_name_ar' => $item->name_ar,
            'pack_count' => $size->packCount,
            'unit_qty' => $size->unitQty,
            'unit' => $size->unit->code,
            'sold_by_weight' => $size->byWeight,
        ];
        try {
            $variant = $this->catalog->import($row);
        } catch (InvalidArgumentException $e) {
            // The group already compares another unit ("Bread" per kg, this loaf per piece): a sibling group for this one.
            if (! str_contains($e->getMessage(), 'does not match group')) {
                throw $e;
            }
            $variant = $this->catalog->import([...$row, 'product_group' => $group.' ('.self::DIMENSION_LABELS[$size->unit->dimension->value].')']);
        }
        $item->update(['product_variant_id' => $variant->id, 'match_method' => 'created']);

        return $isNew;
    }

    /**
     * A fuzzy name match (VariantMatcher) is only trusted when it is plainly the same product: the same brand,
     * the same numbers and model codes, and most words in common. Without this, "1 pc" goods match whatever
     * similar thing exists ("LG Refrigerator LTB452" → "Toshiba Refrigerator GR-RT559"), since size can't tell them apart.
     */
    private function sameProduct(BrochureItem $item, array $hint): bool
    {
        $product = ProductVariant::with('product.brand')->find($item->product_variant_id)?->product;
        if (! $product) {
            return false;
        }
        $brand = fn (?string $name) => $name ? Str::slug($name) : null;
        if ($brand($item->brand_text) !== $brand($product->brand?->getTranslation('name', 'en'))) {
            return false;
        }
        $tokens = fn (string $s) => array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)))));
        $mine = $tokens(trim((string) ($hint['product'] ?? '')) ?: $item->name);
        $theirs = $tokens($product->getTranslation('name', 'en'));
        $digits = fn (array $t) => array_values(array_filter($t, fn ($w) => preg_match('/\d/', $w)));
        $a = $digits($mine);
        $b = $digits($theirs);
        sort($a);
        sort($b);
        if ($a !== $b) {
            return false;
        }
        $union = count(array_unique([...$mine, ...$theirs]));

        return $union > 0 && count(array_intersect($mine, $theirs)) / $union >= 0.7;
    }

    /** "Electronics > Mobile Phones": each level found by slug or name, else created under the one before. */
    private function category(string $path): Category
    {
        $parent = null;
        foreach (array_filter(array_map('trim', explode('>', $path))) as $name) {
            $found = Category::where('slug', Str::slug($name))->first()
                ?? Category::whereRaw("lower(name->>'en') = ?", [mb_strtolower($name)])->first();
            $parent = $found ?? Category::create([
                'parent_id' => $parent?->id,
                'name' => ['en' => $name],
                'slug' => Str::slug($name),
                'is_active' => true,
                'sort' => Category::where('parent_id', $parent?->id)->max('sort') + 1,
            ]);
        }

        return $parent ?? throw new InvalidArgumentException("empty category [{$path}]");
    }

    /** A photo for a product that has none, cropped from the page at the item's box; made into sizes now (the queue may be idle). */
    private function addPhoto(BrochureItem $item, BrochurePage $page): bool
    {
        $variant = ProductVariant::with('product.images')->find($item->product_variant_id);
        $box = $item->bbox;
        if (! $variant || $variant->product->images->isNotEmpty() || ! is_array($box) || ! $page->image_path) {
            return false;
        }
        $source = @imagecreatefromjpeg(Storage::disk(config('flyers.disk'))->path($page->image_path));
        if (! $source) {
            return false;
        }
        [$w, $h] = [imagesx($source), imagesy($source)];
        $padX = $box['w'] * self::CROP_PAD;
        $padY = $box['h'] * self::CROP_PAD;
        $x = (int) max(0, ($box['x'] - $padX) * $w);
        $y = (int) max(0, ($box['y'] - $padY) * $h);
        $cw = (int) min($w - $x, ($box['w'] + 2 * $padX) * $w);
        $ch = (int) min($h - $y, ($box['h'] + 2 * $padY) * $h);
        if ($cw < 40 || $ch < 40 || ! ($crop = imagecrop($source, ['x' => $x, 'y' => $y, 'width' => $cw, 'height' => $ch]))) {
            return false;
        }
        ob_start();
        imagejpeg($crop, null, 90);
        $bytes = ob_get_clean();

        $slug = $variant->product->slug;
        $path = 'products/'.$slug.'-'.Str::lower(Str::random(8)).'.jpg';
        GenerateImageSizes::disk()->put($path, $bytes);
        $image = ProductImage::withoutEvents(fn () => $variant->product->images()->create([
            'path' => $path,
            'alt' => ['en' => $variant->product->getTranslation('name', 'en')],
            'credit' => null,
        ]));
        GenerateImageSizes::dispatchSync($image->id);

        return true;
    }
}
