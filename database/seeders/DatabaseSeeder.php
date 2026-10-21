<?php

// torsade/database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * No user is ever seeded: accounts have no default credentials. The first
     * administrator is created with `php artisan torsade:create-admin`.
     */
    public function run(): void
    {
        //
    }
}
