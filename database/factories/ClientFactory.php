<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_category_id' => fn () => ClientCategory::firstOrCreate(['name' => 'UMKM'])->id,
            'name' => fake()->unique()->company(),
            'status' => 'active',
        ];
    }
}
