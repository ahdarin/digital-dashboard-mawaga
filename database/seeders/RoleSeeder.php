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
        ];

        foreach ($roles as $name) {
            Role::firstOrCreate([
                'name' => $name,
            ]);
        }

        // Manager Demo (local/dev only — seeded account has a known password,
        // so it must never be created in staging/production environments)
        if (app()->environment('local')) {
            $managerRole = Role::where('name', 'Manager')->first();

            $managerDemo = User::firstOrCreate(
                [
                    'email' => 'admin@523studio.test',
                ],
                [
                    'name' => 'Manager Demo',
                    'password' => bcrypt('password'),
                    'status' => 'active',
                ]
            );
            $managerDemo->roles()->syncWithoutDetaching([$managerRole->id]);
        }

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
