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
            'CEO', 'Content Creator', 'Graphic Designer', 'MSO', 'Admin',
            'Client Owner', 'Client Member',
        ];

        foreach ($roles as $name) {
            Role::firstOrCreate(['name' => $name]);
        }

        if (User::count() === 0) {
            User::create([
                'role_id' => Role::where('name', 'Admin')->first()->id,
                'name' => 'Admin Demo',
                'email' => 'admin@523studio.test',
                'password' => bcrypt('password'),
            ]);
        }
    }
}