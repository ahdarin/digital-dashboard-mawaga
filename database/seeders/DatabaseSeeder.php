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
            MasterDataSeeder::class,
        ]);

        // Data demo (client/konten/metrik fiktif) - cuma buat dev/testing,
        // sama seperti akun Manager Demo di RoleSeeder. Jangan pernah
        // ter-seed otomatis di staging/production - env-gate eksplisit
        // (bukan cuma di-comment-out) biar aman walau baris ini ketinggalan
        // ter-uncomment di suatu deploy.
        if (app()->environment('local')) {
            $this->call(DemoSeeder::class);
        }
    }
}
