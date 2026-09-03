<?php

namespace Database\Factories;

use App\Models\ClientCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientCategory>
 */
class ClientCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word(),
        ];
    }
}
