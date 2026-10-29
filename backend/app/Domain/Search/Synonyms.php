<?php

namespace App\Domain\Search;

use App\Domain\Catalog\Brand;
use App\Domain\Offers\Offer;
use Throwable;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Pushes the admin synonyms, plus each brand's other spellings ("IG" = "India Gate"), to one Typesense
 * synonym set and links it to the offers collection. The whole set is replaced on every sync, so it is
 * always an exact copy of the database. Runs after each change, after a reindex and hourly.
 */
final class Synonyms
{
    public function __construct(private readonly Client $client) {}

    public static function setName(): string
    {
        return config('scout.prefix').'grocery';
    }

    /** @return int the number of synonym items now in Typesense */
    public function sync(): int
    {
        $items = [
            ...Synonym::query()->where('is_active', true)->orderBy('id')->get()
                ->filter(fn (Synonym $s) => count($s->terms) >= ($s->root ? 1 : 2))
                ->map->toTypesense(),
            ...Brand::query()->whereNotNull('synonyms')->orderBy('id')->get()
                ->map(fn (Brand $brand) => [
                    'id' => 'brand-'.$brand->id,
                    'synonyms' => Synonym::clean([$brand->getTranslation('name', 'en'), ...$brand->synonyms]),
                ])
                ->filter(fn (array $item) => count($item['synonyms']) >= 2),
        ];

        $this->client->synonymSets->upsert(self::setName(), ['items' => array_values($items)]);
        $this->link();

        return count($items);
    }

    /** For model events: a search outage must not stop an admin from saving. The hourly sync catches up. */
    public function syncQuietly(): void
    {
        try {
            $this->sync();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A collection can only name a set that exists, so the link is made here rather than in the schema. */
    private function link(): void
    {
        $collection = $this->client->collections[(new Offer)->searchableAs()];
        try {
            $linked = $collection->retrieve()['synonym_sets'] ?? [];
        } catch (ObjectNotFound) {
            return; // Created on the first index; the next sync links it.
        }
        if (! in_array(self::setName(), $linked, true)) {
            $collection->update(['synonym_sets' => [...$linked, self::setName()]]);
        }
    }
}
