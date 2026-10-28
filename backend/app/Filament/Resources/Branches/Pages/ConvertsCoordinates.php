<?php

namespace App\Filament\Resources\Branches\Pages;

use Clickbar\Magellan\Data\Geometries\Point;

/** The form edits latitude/longitude; the model stores one PostGIS point. */
trait ConvertsCoordinates
{
    protected function coordinatesToLocation(array $data): array
    {
        $data['location'] = Point::makeGeodetic((float) $data['latitude'], (float) $data['longitude']);
        unset($data['latitude'], $data['longitude']);

        return $data;
    }
}
