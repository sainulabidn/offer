<?php

namespace App\Domain\Retail;

use App\Domain\Geo\Country;
use App\Domain\Settings\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Adds the UAE supermarket groups and banners in database/data/uae-retailers.json, with logos from
 * database/data/retailer-logos. Runs on every deploy but only acts when the file changed, and only fills
 * gaps: a store staff already edited keeps its name, grade and details, and a deleted one stays deleted
 * until the file changes again.
 */
final class ImportRetailDirectory
{
    private const string FILE = 'data/uae-retailers.json';

    private const string LOGOS = 'data/retailer-logos';

    private const string DONE = 'retail_directory_hash';

    /** @return array{groups: int, chains: int, filled: int}|null what changed, or null when the file was already imported */
    public function __invoke(bool $force = false): ?array
    {
        $path = database_path(self::FILE);
        $hash = hash_file('sha256', $path);
        if (! $force && SiteSetting::where('key', self::DONE)->value('value') === $hash) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data['groups'] ?? null) || ! is_array($data['chains'] ?? null)) {
            throw new RuntimeException('database/'.self::FILE.' is missing or broken.');
        }

        $counts = ['groups' => 0, 'chains' => 0, 'filled' => 0];
        DB::transaction(function () use ($data, &$counts) {
            $groups = [];
            foreach ($data['groups'] as $row) {
                $group = RetailGroup::firstOrNew(['slug' => $row['slug']]);
                $counts[$group->exists ? 'filled' : 'groups'] += (int) $this->fill($group, [
                    'name' => ['en' => $row['name']],
                    'website' => $row['website'],
                    'logo_path' => $this->logo($row['slug']),
                ]);
                $groups[$row['slug']] = $group->id;
            }

            $uae = Country::where('code', 'AE')->value('id');
            foreach ($data['chains'] as $row) {
                $chain = Chain::firstOrNew(['slug' => $row['slug']]);
                $counts[$chain->exists ? 'filled' : 'chains'] += (int) $this->fill($chain, [
                    'name' => ['en' => $row['name']],
                    'country_id' => $uae,
                    'retail_group_id' => $row['group'] ? $groups[$row['group']] : null,
                    'format' => ChainFormat::from($row['format']),
                    'grade' => $row['grade'],
                    'website' => $row['website'],
                    'logo_path' => $this->logo($row['slug']),
                    'staff_notes' => trim('From the UAE retailer research list. '.($row['note'] ?? '')),
                ]);
            }
        });

        SiteSetting::updateOrCreate(['key' => self::DONE], ['value' => $hash]);

        return $counts;
    }

    /** New records take every value; existing ones only fill empty fields. Returns whether anything changed. */
    private function fill(RetailGroup|Chain $record, array $values): bool
    {
        foreach ($values as $field => $value) {
            if ($value !== null && (! $record->exists || blank($record->getAttribute($field)))) {
                $record->setAttribute($field, $value);
            }
        }

        if (! $record->isDirty()) {
            return false;
        }
        $record->save();

        return true;
    }

    /** Copies the logo to the media disk, once; null when there is no logo for this slug. */
    private function logo(string $slug): ?string
    {
        $source = database_path(self::LOGOS."/{$slug}.png");
        if (! is_file($source)) {
            return null;
        }

        $target = "logos/{$slug}.png";
        $disk = Storage::disk(config('filesystems.media_disk'));
        if (! $disk->exists($target)) {
            $disk->put($target, (string) file_get_contents($source), 'public');
        }

        return $target;
    }
}
