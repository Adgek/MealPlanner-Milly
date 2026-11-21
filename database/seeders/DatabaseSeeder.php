<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Two different jobs, kept in separate seeders on purpose:
     *
     *   HouseholdDefaultsSeeder  the starting settings and cook schedule. Real
     *                            configuration that every database needs, so the
     *                            container entrypoint runs it on every boot.
     *   ExampleDataSeeder        the Bulgogi/tacos/chili sample set. Useful for
     *                            development, and not the household's own data —
     *                            MM-5 loads that.
     *
     * `db:seed` runs both, which is what a developer wants. Production setup runs
     * only the first.
     */
    public function run(): void
    {
        $this->call([
            HouseholdDefaultsSeeder::class,
            ExampleDataSeeder::class,
        ]);
    }
}
