<?php

namespace App\Domain\Flyers;

use App\Domain\Access\Access;
use App\Domain\Access\Level;
use App\Domain\Access\StoreScope;
use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\PageStatus;
use App\Domain\Retail\Chain;
use App\Filament\Imports\OfferImportGuide;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts reading a flyer: one PDF, or photos of its pages. Runs in the upload request, so the uploader's
 * rights, daily quota and duplicates are checked before anything costs money; then it makes the brochure
 * (status Processing) and queues PrepareFlyer. A file already imported for the store returns that brochure
 * instead (wasRecentlyCreated is false).
 *
 * Who may cover which branches follows the CSV import: store users name their branches; the whole store
 * needs "edit" on the chain or publish rights over it.
 */
final class StartFlyerImport
{
    public const int MAX_IMAGES = 40;

    public function __construct(private readonly PdfTools $tools) {}

    /**
     * @param  ?User  $user  null for automated sources (no person, no quota)
     * @param  list<string>  $files  paths on the flyer disk: one PDF, or page photos in order
     * @param  list<int>  $branchIds  empty = every branch of the store
     * @param  array{title?: ?string, valid_from?: ?string, valid_to?: ?string, mode?: string, source?: string, source_url?: ?string}  $options
     */
    public function __invoke(?User $user, Chain $chain, array $files, array $branchIds = [], array $options = []): Brochure
    {
        if ($user) {
            $this->authorize($user, $chain, $branchIds);
        }
        $disk = Storage::disk(config('flyers.disk'));
        $files = array_values($files);
        $type = $this->type($files);
        $hash = $type === 'pdf'
            ? hash_file('sha256', $disk->path($files[0]))
            : hash('sha256', implode('', array_map(fn (string $file) => hash_file('sha256', $disk->path($file)), $files)));

        $existing = Brochure::query()
            ->where('chain_id', $chain->id)
            ->where('file_hash', $hash)
            ->whereNotIn('status', [BrochureStatus::Rejected, BrochureStatus::Archived])
            ->first();
        if ($existing) {
            $disk->delete($files);

            return $existing;
        }

        $pages = $type === 'pdf' ? $this->pdfPages($disk->path($files[0])) : count($files);
        $this->checkLimits($user, $pages);

        $today = now(config('app.market_timezone'));
        $title = trim((string) ($options['title'] ?? '')) ?: "{$chain->name} flyer {$today->format('j M Y')}";
        $datesGiven = filled($options['valid_from'] ?? null) && filled($options['valid_to'] ?? null);

        $brochure = DB::transaction(function () use ($user, $chain, $files, $branchIds, $options, $type, $hash, $pages, $today, $title, $datesGiven, $disk) {
            $brochure = new Brochure([
                'chain_id' => $chain->id,
                'type' => $type,
                'title' => ['en' => $title],
                'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
                // Until the AI reads the dates off the pages (FinishFlyer), assume this week.
                'valid_from' => $datesGiven ? $options['valid_from'] : $today->toDateString(),
                'valid_to' => $datesGiven ? $options['valid_to'] : $today->copy()->addDays(6)->toDateString(),
                'status' => BrochureStatus::Processing,
                'source' => $options['source'] ?? 'admin_upload',
                'source_url' => $options['source_url'] ?? null,
                'file_hash' => $hash,
                'page_count' => $pages,
                'ai_mode' => $options['mode'] ?? 'fast',
                'detected' => ['dates_given' => $datesGiven],
            ]);
            $brochure->created_by = $user?->id;
            $brochure->ai_requested_by = $user?->id;
            $brochure->save();
            foreach (array_unique(array_map('intval', $branchIds)) as $branchId) {
                $brochure->scopes()->create(['scope_type' => 'branch', 'scope_id' => $branchId]);
            }

            // Into the brochure's own folder; page photos become its pages straight away.
            $folder = config('flyers.directory')."/{$brochure->id}";
            if ($type === 'pdf') {
                $disk->move($files[0], "{$folder}/flyer.pdf");
                $brochure->update(['original_path' => "{$folder}/flyer.pdf"]);
            } else {
                foreach ($files as $i => $file) {
                    $path = "{$folder}/upload-".($i + 1).'.'.(pathinfo($file, PATHINFO_EXTENSION) ?: 'jpg');
                    $disk->move($file, $path);
                    $brochure->pages()->create(['page_no' => $i + 1, 'image_path' => $path, 'ai_status' => PageStatus::Pending]);
                }
                $brochure->update(['original_path' => $folder]);
            }

            return $brochure;
        });

        PrepareFlyer::dispatch($brochure->id);

        return $brochure;
    }

    /** A brochure added with its file on the brochure form: read that file like an imported flyer. */
    public function existing(User $user, Brochure $brochure): void
    {
        if (! Access::allows($user, 'brochures', 'ai_import') || ! Access::allows($user, 'brochures', 'edit', $brochure)) {
            throw ValidationException::withMessages(['files' => "You can't read this brochure with AI."]);
        }
        $file = (string) $brochure->original_path;
        $type = $this->type([$file]);
        $pages = $type === 'pdf' ? $this->pdfPages(Storage::disk(config('flyers.disk'))->path($file)) : 1;
        $this->checkLimits($user, $pages);

        DB::transaction(function () use ($user, $brochure, $file, $type, $pages) {
            $brochure->forceFill([
                'type' => $type,
                'file_hash' => hash_file('sha256', Storage::disk(config('flyers.disk'))->path($file)),
                'page_count' => $pages,
                'status' => BrochureStatus::Processing,
                'ai_mode' => 'fast',
                'ai_requested_by' => $user->id,
                'detected' => ['dates_given' => true],
            ])->save();
            if ($type === 'images') {
                $brochure->pages()->firstOrCreate(['page_no' => 1], ['image_path' => $file, 'ai_status' => PageStatus::Pending]);
            }
        });

        PrepareFlyer::dispatch($brochure->id);
    }

    /** May this person import a flyer for this store and these branches? @throws ValidationException */
    public function authorize(User $user, Chain $chain, array $branchIds): void
    {
        if (! Access::allows($user, 'brochures', 'ai_import') || ! OfferImportGuide::chains($user)->whereKey($chain->id)->exists()) {
            throw ValidationException::withMessages(['chain_id' => "You can't import flyers for {$chain->name}."]);
        }
        if ($branchIds === []) {
            if (! self::canCoverWholeStore($user, $chain)) {
                throw ValidationException::withMessages(['branch_ids' => "Choose your branches: you can't add offers for every {$chain->name} branch."]);
            }

            return;
        }
        $mine = OfferImportGuide::branches($user, $chain)->whereKey($branchIds)->count();
        if ($mine !== count(array_unique($branchIds))) {
            throw ValidationException::withMessages(['branch_ids' => "You can't add offers for some of these {$chain->name} branches."]);
        }
    }

    /** Whole-store flyers: people who manage the store, or who may publish its brochures (reviewers). */
    public static function canCoverWholeStore(User $user, Chain $chain): bool
    {
        if (Access::allows($user, 'chains', 'edit', $chain)) {
            return true;
        }

        return match (Access::level($user, 'brochures', 'publish')) {
            Level::All => true,
            Level::Assigned => in_array($chain->id, array_map('intval', StoreScope::for($user)->chainIds), true),
            default => false,
        };
    }

    /** @param  list<string>  $files */
    private function type(array $files): string
    {
        $disk = Storage::disk(config('flyers.disk'));
        $heads = array_map(fn (string $file) => (string) file_get_contents($disk->path($file), length: 8), $files);
        $isPdf = fn (string $head) => str_starts_with($head, '%PDF-');

        if (count($files) === 1 && $isPdf($heads[0])) {
            return 'pdf';
        }
        if ($files === [] || count($files) > self::MAX_IMAGES || collect($heads)->contains($isPdf)) {
            throw ValidationException::withMessages(['files' => 'Upload one PDF, or up to '.self::MAX_IMAGES.' photos of the pages.']);
        }
        foreach ($files as $file) {
            if (! in_array(@getimagesize($disk->path($file))[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                throw ValidationException::withMessages(['files' => 'Photos must be JPG, PNG or WebP.']);
            }
        }

        return 'images';
    }

    private function pdfPages(string $pdf): int
    {
        try {
            $info = $this->tools->info($pdf);
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['files' => "This PDF can't be read. Save it again from the original or upload photos of the pages."]);
        }
        if ($info['encrypted']) {
            throw ValidationException::withMessages(['files' => 'This PDF is password-protected. Upload one without a password.']);
        }

        return $info['pages'];
    }

    private function checkLimits(?User $user, int $pages): void
    {
        $max = (int) FlyerSettings::get('max_pages');
        if ($pages > $max) {
            throw ValidationException::withMessages(['files' => "This flyer has {$pages} pages; the most we read at once is {$max}."]);
        }
        if (! CostCalculator::hasPrice((string) FlyerSettings::get('model'))) {
            throw ValidationException::withMessages(['files' => 'The flyer AI model has no price set yet. An admin sets it under Offers → Flyer AI settings.']);
        }
        if ($user && ($message = FlyerBudget::quotaMessage($user, $pages))) {
            throw ValidationException::withMessages(['files' => $message]);
        }
    }
}
