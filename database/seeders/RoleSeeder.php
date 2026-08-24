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
            'Desain Grafis',
            'SMO',
            'Copywriter',
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
        // bukan bootstrap tim beneran (beda dari Ahda/Surdik/Ghazi di bawah
        // yang emailnya asli), jadi lebih tepat jadi bagian data demo
        // eksplisit, bukan default seeding.

        // CEO
        $ceoRole = Role::where('name', 'CEO')->first();

        $ahda = User::firstOrCreate(
            [
                'email' => 'ahdaalamin2506@gmail.com',
            ],
            [
                'name' => 'Ahda',
                'status' => 'active',
            ]
        );
        $ahda->roles()->syncWithoutDetaching([$ceoRole->id]);

        $surdik = User::firstOrCreate(
            [
                'email' => 'surdik2811@gmail.com',
            ],
            [
                'name' => 'Surdik',
                'status' => 'active',
            ]
        );
        $surdik->roles()->syncWithoutDetaching([$ceoRole->id]);

        $ghazi = User::firstOrCreate(
            [
                'email' => 'ghazifadhlullah31@gmail.com',
            ],
            [
                'name' => 'Ghazi',
                'status' => 'active',
            ]
        );
        $ghazi->roles()->syncWithoutDetaching([$ceoRole->id]);
    }
}
