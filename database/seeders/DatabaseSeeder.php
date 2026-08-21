<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database - HANYA bootstrap/reference wajib
     * (role, permission, master data pilihan dropdown). TIDAK PERNAH
     * memanggil DemoSeeder di sini lagi, di environment MANAPUN termasuk
     * local - dev DB sekarang berisi data real (planner import + Instagram
     * API), jadi `migrate:fresh --seed` harus aman dijalankan kapan saja
     * tanpa mencemari operational data dengan client/content/metric fiktif.
     *
     * Butuh data demo buat testing/eksplorasi fitur baru? Jalankan eksplisit:
     *   php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            MasterDataSeeder::class,
        ]);
    }
}
