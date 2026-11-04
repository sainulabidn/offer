<?php

namespace App\Domain\Flyers;

use App\Domain\Brochures\Brochure;
use Illuminate\Support\Facades\DB;

/**
 * Suggests which of the store's branches a flyer is for, from places printed on it ("Valid at Al Qusais
 * branch only", "Abu Dhabi & Al Ain"): branch names, emirates and areas, and known places (place_names)
 * within a few km of a branch. Only suggestions: the branches a flyer covers come from the uploader or the
 * source, and a reviewer applies these with one click.
 */
final class LocationResolver
{
    private const int NEAR_METRES = 3000;

    /**
     * @param  list<string>  $places  as printed
     * @return list<array{id: int, name: string, because: string}>
     */
    public function suggest(Brochure $brochure, array $places): array
    {
        $found = [];
        foreach (array_unique(array_filter(array_map('trim', $places))) as $place) {
            $needle = mb_strtolower($place);
            $rows = DB::select(<<<'SQL'
                SELECT b.id, b.name->>'en' AS name
                FROM branches b
                LEFT JOIN regions r ON r.id = b.region_id
                WHERE b.chain_id = ? AND b.is_active
                  AND (
                       similarity(lower(b.name->>'en'), ?) >= 0.5
                    OR lower(r.name->>'en') = ?
                    OR EXISTS (SELECT 1 FROM regions e WHERE e.id = r.parent_id AND lower(e.name->>'en') = ?)
                    OR EXISTS (
                         SELECT 1 FROM place_names pn
                         WHERE similarity(lower(pn.name->>'en'), ?) >= 0.6
                           AND ST_DWithin(b.location, pn.location, ?)
                    )
                  )
                ORDER BY b.name->>'en'
                SQL, [$brochure->chain_id, $needle, $needle, $needle, $needle, self::NEAR_METRES]);
            foreach ($rows as $row) {
                $found[$row->id] ??= ['id' => (int) $row->id, 'name' => (string) $row->name, 'because' => $place];
            }
        }

        return array_values($found);
    }
}
