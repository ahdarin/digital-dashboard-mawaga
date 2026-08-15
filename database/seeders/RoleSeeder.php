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
            'Client Owner',
        ];

        foreach ($roles as $name) {
            Role::firstOrCreate([
                'name' => $name,
            ]);
        }

        // Manager Demo
        $managerRole = Role::where('name', 'Manager')->first();

        User::firstOrCreate(
            [
                'email' => 'admin@523studio.test',
            ],
            [
                'role_id' => $managerRole->id,
                'name' => 'Manager Demo',
                'password' => bcrypt('password'),
                'status' => 'active',
            ]
        );

        // CEO
        $ceoRole = Role::where('name', 'CEO')->first();

        User::firstOrCreate(
            [
                'email' => 'ahdaalamin2506@gmail.com',
            ],
            [
                'role_id' => $ceoRole->id,
                'name' => 'Ahda',
                'status' => 'active',
            ]
        );
        $ceoRole = Role::where('name', 'CEO')->first();

        User::firstOrCreate(
            [
                'email' => 'surdik2811@gmail.com',
            ],
            [
                'role_id' => $ceoRole->id,
                'name' => 'Surdik',
                'status' => 'active',
            ]
        );
        $ceoRole = Role::where('name', 'CEO')->first();

        User::firstOrCreate(
            [
                'email' => 'ghazifadhlullah31@gmail.com',
            ],
            [
                'role_id' => $ceoRole->id,
                'name' => 'Ghazi',
                'status' => 'active',
            ]
        );
    }
}
