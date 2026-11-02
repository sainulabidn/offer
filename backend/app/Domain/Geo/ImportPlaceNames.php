<?php

namespace App\Domain\Geo;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replaces the location-search list with database/data/uae-places.json, a list of UAE areas, malls,
 * stations and landmarks from OpenStreetMap, in rows of [type, English name, Arabic name, lat, lng].
 */
final class ImportPlaceNames
{
    private const string FILE = 'data/uae-places.json';

    /** @return int how many places are now listed */
    public function __invoke(): int
    {
        $rows = json_decode((string) file_get_contents(database_path(self::FILE)), true);
        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('database/'.self::FILE.' is missing or empty.');
        }

        DB::transaction(function () use ($rows) {
            DB::table('place_names')->delete();

            foreach (array_chunk($rows, 500) as $chunk) {
                $values = [];
                $bindings = [];
                foreach ($chunk as [$type, $english, $arabic, $lat, $lng]) {
                    $values[] = '(?, ?::jsonb, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, now(), now())';
                    array_push($bindings, $type, json_encode(array_filter(['en' => $english, 'ar' => $arabic]), JSON_UNESCAPED_UNICODE), $lng, $lat);
                }
                DB::insert('INSERT INTO place_names (type, name, location, created_at, updated_at) VALUES '.implode(',', $values), $bindings);
            }
        });

        return count($rows);
    }
}
