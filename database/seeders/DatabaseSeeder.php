<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            ReferenceDataSeeder::class,
        ]);

        // Data demo (client/konten/metrik fiktif) - cuma buat dev/testing,
        // sama seperti akun Manager Demo di RoleSeeder. Jangan pernah
        // ter-seed otomatis di staging/production.
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}