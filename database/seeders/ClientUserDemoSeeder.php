<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class ClientUserDemoSeeder extends Seeder
{
    public function run(): void
    {
        $category = ClientCategory::firstOrCreate(['name' => 'UMKM']);

        $clients = [
            [
                'company' => 'TechNova Inc.',
                'brand' => 'TechNova',
                'user' => 'Client Demo',
                'email' => 'client-demo@technova.test',
                'phone' => '6281275471093',
            ],
            [
                'company' => 'FreshBite Indonesia',
                'brand' => 'FreshBite',
                'user' => 'Budi Santoso',
                'email' => 'budi@freshbite.test',
                'phone' => '6282288706114',
            ],
            [
                'company' => 'Urban Coffee',
                'brand' => 'Urban Coffee',
                'user' => 'Andi Pratama',
                'email' => 'andi@urbancoffee.test',
                'phone' => '6282222222222',
            ],
        ];

        $role = Role::firstOrCreate([
            'name' => 'Client Owner'
        ]);


        foreach ($clients as $item) {
            $client = Client::firstOrCreate(
                ['name' => $item['company']],
                [
                    'client_category_id' => $category->id,
                    'brand_name' => $item['brand'],
                    'status' => 'active',
                ]
            );

            User::firstOrCreate(
                ['phone_number' => $item['phone']],
                [
                    'role_id' => $role->id,
                    'client_id' => $client->id,
                    'name' => $item['user'],
                    'email' => $item['email'],
                    'status' => 'active',
                ]
            );
        }
    }
}