<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ContentPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPlan>
 */
class ContentPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'created_by' => User::factory(),
            'month' => $this->faker->numberBetween(1, 12),
            'year' => (int) now()->year,
            'status' => 'approved',
        ];
    }
}
