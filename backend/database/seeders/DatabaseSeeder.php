<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Model events stay on: variants compute net_base_qty and categories their path while saving.
     */
    public function run(): void
    {
        $this->call([
            UnitSeeder::class,
            GeoSeeder::class,
            CatalogStructureSeeder::class,
            StaffSeeder::class,
            SynonymSeeder::class,
        ]);

        if (app()->isLocal()) {
            $this->call([DemoCatalogSeeder::class, DemoOffersSeeder::class]);
        }
    }
}
