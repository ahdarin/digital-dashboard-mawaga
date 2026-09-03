<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentPillar;
use App\Models\ContentPlan;
use App\Models\ContentType;
use App\Models\Platform;
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
            'content_pillar_id' => fn () => ContentPillar::firstOrCreate(['name' => 'Education'])->id,
            'content_type_id' => fn () => ContentType::firstOrCreate(['name' => 'Video'])->id,
            'platform_id' => fn () => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'title' => fake()->sentence(4),
            'deadline_at' => now()->addDays(3),
        ];
    }
}
