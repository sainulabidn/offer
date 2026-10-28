<?php

namespace Database\Seeders;

use App\Domain\Geo\Country;
use App\Domain\Geo\Region;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GeoSeeder extends Seeder
{
    public function run(): void
    {
        $uae = Country::updateOrCreate(['code' => 'AE'], [
            'name' => ['en' => 'United Arab Emirates', 'ar' => 'الإمارات العربية المتحدة'],
            'currency' => 'AED',
            'timezone' => 'Asia/Dubai',
            'default_locale' => 'en',
        ]);

        // Approximate centre points; refine in the admin. emirate => [lat, lng, ar, [area => [lat, lng]]]
        $emirates = [
            'Dubai' => [25.2048, 55.2708, 'دبي', [
                'Al Nahda' => [25.2894, 55.3704],
                'Al Barsha' => [25.1110, 55.2000],
                'Deira' => [25.2711, 55.3075],
                'Al Karama' => [25.2465, 55.3036],
                'Dubai Marina' => [25.0805, 55.1403],
                'International City' => [25.1640, 55.4090],
            ]],
            'Sharjah' => [25.3463, 55.4209, 'الشارقة', [
                'Al Majaz' => [25.3260, 55.3850],
                'Al Nahda Sharjah' => [25.3010, 55.3740],
                'Muwaileh' => [25.3020, 55.4580],
            ]],
            'Abu Dhabi' => [24.4539, 54.3773, 'أبوظبي', [
                'Al Reem Island' => [24.4990, 54.4040],
                'Khalifa City' => [24.4190, 54.5780],
                'Mussafah' => [24.3500, 54.5000],
            ]],
            'Ajman' => [25.4052, 55.5136, 'عجمان', [
                'Al Nuaimiya' => [25.3900, 55.4500],
            ]],
            'Umm Al Quwain' => [25.5647, 55.5552, 'أم القيوين', []],
            'Ras Al Khaimah' => [25.8007, 55.9762, 'رأس الخيمة', []],
            'Fujairah' => [25.1288, 56.3265, 'الفجيرة', []],
        ];

        $sort = 0;
        foreach ($emirates as $name => [$lat, $lng, $arabic, $areas]) {
            $emirate = Region::updateOrCreate(
                ['country_id' => $uae->id, 'slug' => Str::slug($name)],
                ['type' => 'emirate', 'name' => ['en' => $name, 'ar' => $arabic], 'centroid' => Point::makeGeodetic($lat, $lng), 'sort' => $sort++],
            );

            $areaSort = 0;
            foreach ($areas as $area => [$areaLat, $areaLng]) {
                Region::updateOrCreate(
                    ['country_id' => $uae->id, 'slug' => Str::slug($area)],
                    ['parent_id' => $emirate->id, 'type' => 'area', 'name' => ['en' => $area], 'centroid' => Point::makeGeodetic($areaLat, $areaLng), 'sort' => $areaSort++],
                );
            }
        }
    }
}
