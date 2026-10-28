<?php

namespace Database\Seeders;

use App\Domain\Catalog\Unit;
use App\Domain\Catalog\UnitDimension;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        // code => [dimension, factor to base unit, English name, aliases seen in brochures]
        $units = [
            'g' => [UnitDimension::Mass, '1', 'gram', ['g', 'gm', 'gms', 'gr', 'grm', 'gram', 'grams']],
            'kg' => [UnitDimension::Mass, '1000', 'kilogram', ['kg', 'kgs', 'kilo', 'kilogram']],
            'mg' => [UnitDimension::Mass, '0.001', 'milligram', ['mg']],
            'lb' => [UnitDimension::Mass, '453.592', 'pound', ['lb', 'lbs']],
            'oz' => [UnitDimension::Mass, '28.3495', 'ounce', ['oz']],
            'ml' => [UnitDimension::Volume, '1', 'millilitre', ['ml', 'mls', 'millilitre', 'milliliter']],
            'cl' => [UnitDimension::Volume, '10', 'centilitre', ['cl']],
            'l' => [UnitDimension::Volume, '1000', 'litre', ['l', 'lt', 'ltr', 'ltrs', 'litre', 'liter', 'litres']],
            'fl_oz' => [UnitDimension::Volume, '29.5735', 'fluid ounce', ['fl oz', 'floz']],
            'pc' => [UnitDimension::Count, '1', 'piece', ['pc', 'pcs', 'piece', 'pieces', "'s", 'nos', 'pack']],
            'dozen' => [UnitDimension::Count, '12', 'dozen', ['dozen', 'doz']],
            'm' => [UnitDimension::Length, '1', 'metre', ['m', 'mtr', 'metre', 'meter']],
            'cm' => [UnitDimension::Length, '0.01', 'centimetre', ['cm']],
            'sheet' => [UnitDimension::Sheets, '1', 'sheet', ['sheet', 'sheets', 'ply sheets']],
            'wash' => [UnitDimension::Washes, '1', 'wash', ['wash', 'washes', 'load', 'loads']],
        ];

        foreach ($units as $code => [$dimension, $factor, $name, $aliases]) {
            Unit::updateOrCreate(['code' => $code], [
                'dimension' => $dimension,
                'factor_to_base' => $factor,
                'name' => ['en' => $name],
                'aliases' => $aliases,
            ]);
        }
    }
}
