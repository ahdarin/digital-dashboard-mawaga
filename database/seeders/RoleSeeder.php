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

        // CEO bootstrap - satu akun resmi 523 Studio, bukan akun personal.
        // (Sebelumnya 3 akun Gmail personal - Ahda/Surdik/Ghazi - diganti
        // satu akun resmi ini per keputusan pemilik produk.)
        // Sempat salah diganti ke akun personal Ghazi (fa4f2e1) buat
        // kemudahan testing lokal - dikembalikan ke akun resmi sesuai
        // keputusan di atas, akun personal testing tidak ikut di-bootstrap
        // di sini lagi.
        $ceoRole = Role::where('name', 'CEO')->first();

        $ceo = User::firstOrCreate(
            [
                'email' => 'hello523studio@gmail.com',
            ],
            [
                'name' => '523 Studio',
                'status' => 'active',
                'login_enabled' => true,
            ]
        );
        $ceo->roles()->syncWithoutDetaching([$ceoRole->id]);

        if (! $ceo->login_enabled) {
            $ceo->update(['login_enabled' => true]);
        }
    }
}
