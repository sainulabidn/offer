<?php

namespace App\Domain\Geo;

use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * An area, mall, station or landmark for the location search. Built from OpenStreetMap (places:import)
 * and kept apart from store data, because this list stays under OpenStreetMap's ODbL licence.
 */
class PlaceName extends Model
{
    use HasTranslations;

    protected $guarded = [];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return ['location' => Point::class];
    }
}
