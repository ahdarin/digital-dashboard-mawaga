<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            'CEO',
            'Manager',
            'Content Creator',
            'Graphic Designer',
            'SMO',
            'Copywriter',
            'Admin',
        ];

        foreach ($roles as $name) {
            Role::firstOrCreate([
                'name' => $name,
            ]);
        }

        // "Manager Demo" (password diketahui: "password") DIPINDAH ke
        // DemoSeeder (audit dummy-data Agustus 2026) - sebelumnya cuma
        // di-env-gate 'local', tapi dev DB kita SENDIRI selalu 'local', jadi
        // tetap ikut ter-create tiap `migrate:fresh --seed` default. Ini
        // bukan bootstrap tim beneran, jadi lebih tepat jadi bagian data
        // demo eksplisit, bukan default seeding.

        // CEO bootstrap (audit Phase 4.3 - cleanup dari hardcoded email
        // literal yang sempat drift 2x tanpa update yang konsisten, lihat
        // commit fa4f2e1 & audit Phase 4.2). Email CEO SEKARANG satu-
        // satunya sumber kebenaran: config('organization.ceo_email')
        // (CEO_EMAIL di .env) - BUKAN literal di source code manapun lagi.
        // Seeder ini HANYA meng-assign role ke user yang SUDAH ADA (bukan
        // lagi firstOrCreate - jangan fabricate akun baru dengan nama/
        // password-less login_enabled hardcode di seeder), dan skip AMAN
        // dengan pesan console jelas kalau config atau user-nya belum ada -
        // TIDAK PERNAH diam-diam fallback ke User::first().
        $ceoRole = Role::where('name', 'CEO')->first();
        $ceoEmail = config('organization.ceo_email');

        if (! $ceoEmail) {
            $this->command?->warn('CEO_EMAIL belum di-set di .env - bootstrap role CEO dilewati. Set CEO_EMAIL lalu jalankan ulang: php artisan db:seed --class=RoleSeeder');

            return;
        }

        $ceo = User::where('email', $ceoEmail)->first();

        if (! $ceo) {
            $this->command?->warn("User dengan email {$ceoEmail} (CEO_EMAIL) belum ada di database - bootstrap role CEO dilewati. Buat user itu dulu (User Management), lalu jalankan ulang seeder ini.");

            return;
        }

        $ceo->roles()->syncWithoutDetaching([$ceoRole->id]);

        if (! $ceo->login_enabled) {
            $ceo->update(['login_enabled' => true]);
        }
    }
}
