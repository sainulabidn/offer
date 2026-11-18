<?php

namespace App\Domain\Retail;

use App\Domain\Brochures\Brochure;
use App\Domain\Brochures\BrochureStatus;
use App\Domain\Brochures\PublishBrochure;
use App\Domain\Geo\Region;
use App\Domain\Offers\Offer;
use App\Domain\Offers\OfferStatus;
use App\Domain\Search\OfferDocument;
use App\Domain\Settings\SiteSetting;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adds the branches in database/data/uae-branches.json (from OpenStreetMap) and uae-branches-extra.json (added
 * by hand) to the stores already set up; live brochures of a store that gains branches are attached to them.
 * Runs on every deploy but only acts when the file changed. A branch staff already have keeps its name,
 * place and details; only empty phone, address and hours are filled in.
 */
final class ImportUaeBranches
{
    private const string FILE = 'data/uae-branches.json';

    /** Branches added by hand where OpenStreetMap has none or too few (same rows, matched by "ref"). */
    private const string EXTRA = 'data/uae-branches-extra.json';

    private const string DONE = 'uae_branches_hash';

    /** An area is used for a branch this close to its centre; further out, the emirate. */
    private const float AREA_KM = 3.0;

    /** @return array{added: int, filled: int, skipped: int}|null null when already imported */
    public function __invoke(bool $force = false): ?array
    {
        $path = database_path(self::FILE);
        $extra = database_path(self::EXTRA);
        $hash = hash('sha256', hash_file('sha256', $path).(is_file($extra) ? hash_file('sha256', $extra) : ''));
        if (! $force && SiteSetting::where('key', self::DONE)->value('value') === $hash) {
            return null;
        }

        $rows = json_decode((string) file_get_contents($path), true)['branches'] ?? null;
        if (! is_array($rows)) {
            throw new RuntimeException('database/'.self::FILE.' is missing or broken.');
        }
        foreach (is_file($extra) ? (json_decode((string) file_get_contents($extra), true)['branches'] ?? []) : [] as $row) {
            $rows[] = [...$row, 'osm' => null, 'ref' => 'extra:'.$row['ref']];
        }

        $chains = Chain::query()->pluck('id', 'slug');
        $emirates = Region::query()->where('type', 'emirate')->get()->keyBy('slug');
        $areas = Region::query()->where('type', 'area')->whereNotNull('centroid')->get()->groupBy('parent_id');
        $counts = ['added' => 0, 'filled' => 0, 'skipped' => 0];
        $grown = [];

        DB::transaction(function () use ($rows, $chains, $emirates, $areas, &$counts, &$grown) {
            foreach ($rows as $row) {
                $chainId = $chains[$row['chain']] ?? null;
                if (! $chainId) {
                    $counts['skipped']++;

                    continue;
                }

                $ref = $row['ref'] ?? "osm:{$row['osm']}";
                $branch = Branch::where('source_ref', $ref)->first();
                if ($branch) {
                    $counts['filled'] += (int) $this->fill($branch, $row);

                    continue;
                }

                $emirate = $emirates[$row['emirate']] ?? null;
                // Saved quietly: a new branch has no brochures to reindex, and 700 audit entries help no one.
                $branch = Branch::withoutEvents(fn () => Branch::create([
                    'chain_id' => $chainId,
                    'region_id' => $this->area($areas->get($emirate?->id, collect()), $row)?->id ?? $emirate?->id,
                    'name' => ['en' => $row['name']],
                    'slug' => $this->slug($chainId, $row['name']),
                    'address' => $row['address'] ? ['en' => $row['address']] : null,
                    'location' => Point::makeGeodetic($row['lat'], $row['lng']),
                    'phone' => $row['phone'] ? Str::limit($row['phone'], 30, '') : null,
                    'source_ref' => $ref,
                ]));
                $this->hours($branch, $row['hours']);
                $counts['added']++;
                $grown[$chainId] = true;
            }
        });

        $this->attachToLiveBrochures(array_keys($grown));
        SiteSetting::updateOrCreate(['key' => self::DONE], ['value' => $hash]);

        return $counts;
    }

    /**
     * Live brochures of stores that just got branches: their branch list is set when published
     * (PublishBrochure), so it is set again, and their offers re-indexed with the new places.
     *
     * @param  list<int>  $chainIds
     */
    private function attachToLiveBrochures(array $chainIds): void
    {
        if ($chainIds === []) {
            return;
        }
        $publish = app(PublishBrochure::class);
        Brochure::query()->whereIn('chain_id', $chainIds)
            ->whereIn('status', [BrochureStatus::Published, BrochureStatus::Scheduled])
            ->each(function (Brochure $brochure) use ($publish) {
                $brochure->branches()->sync($publish->branchIds($brochure));
                Offer::syncSearch($brochure->offers()->where('status', OfferStatus::Published)->with(OfferDocument::RELATIONS)->get());
            });
    }

    private function fill(Branch $branch, array $row): bool
    {
        $changed = false;
        if (! $branch->phone && $row['phone']) {
            $branch->phone = Str::limit($row['phone'], 30, '');
            $changed = true;
        }
        if (! $branch->getTranslation('address', 'en', false) && $row['address']) {
            $branch->address = ['en' => $row['address']];
            $changed = true;
        }
        if ($changed) {
            $branch->save();
        }

        return $this->hours($branch, $row['hours']) || $changed;
    }

    /** Weekly hours, only for a branch that has none. */
    private function hours(Branch $branch, ?array $hours): bool
    {
        if (! $hours || $branch->hours()->exists()) {
            return false;
        }
        $branch->hours()->createMany(array_map(fn (array $h) => ['weekday' => $h[0], 'opens_at' => $h[1], 'closes_at' => $h[2]], $hours));

        return true;
    }

    private function area(Collection $areas, array $row): ?Region
    {
        return $areas
            ->map(fn (Region $area) => [$area, $this->km($row['lat'], $row['lng'], $area->centroid->getLatitude(), $area->centroid->getLongitude())])
            ->filter(fn (array $pair) => $pair[1] <= self::AREA_KM)
            ->sortBy(1)
            ->first()[0] ?? null;
    }

    /** "al-nahda", then "al-nahda-2" for a second branch of the same store in the same place. */
    private function slug(int $chainId, string $name): string
    {
        $base = Str::slug($name) ?: 'branch';
        $slug = $base;
        for ($n = 2; Branch::where('chain_id', $chainId)->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 12742 * asin(min(1, sqrt($a)));
    }
}
