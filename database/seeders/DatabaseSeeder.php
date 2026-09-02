<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database - HANYA bootstrap/reference wajib
     * (role, permission, master data pilihan dropdown) + roster staf & Client
     * asli (TeamClientSeeder). TIDAK PERNAH memanggil DemoSeeder di sini lagi,
     * di environment MANAPUN termasuk local - dev DB sekarang berisi data
     * real (planner import + Instagram API), jadi `migrate:fresh --seed`
     * harus aman dijalankan kapan saja tanpa mencemari operational data
     * dengan client/content/metric fiktif.
     *
     * TeamClientSeeder SENGAJA menggantikan ContentPlannerSeeder/
     * ContentPlannerPrerequisiteSeeder di sini - keduanya ikut membawa 247
     * ContentItem historis + 19 ContentPlan bulanan dari Excel lama, yang
     * ternyata tidak efektif untuk instalasi baru (install baru jadi penuh
     * data lampau, bukan mulai kosong). Instalasi baru sekarang cuma dapat
     * roster staf + daftar client nyata - konten tetap kosong dari awal.
     * Butuh 247 ContentItem historis itu tetap? Jalankan eksplisit:
     *   php artisan db:seed --class=ContentPlannerPrerequisiteSeeder
     *   php artisan db:seed --class=ContentPlannerSeeder
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
            TeamClientSeeder::class,
        ]);
    }
}
