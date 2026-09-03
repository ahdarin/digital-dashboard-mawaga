<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentItem>
 */
class ContentItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_plan_id' => ContentPlan::factory(),
            'client_id' => Client::factory(),
            'title' => $this->faker->sentence(4),
            'brief' => $this->faker->paragraph(),
            'deadline_at' => now()->addDays(5),
            'is_urgent' => false,
            'is_posted' => false,
        ];
    }
}
