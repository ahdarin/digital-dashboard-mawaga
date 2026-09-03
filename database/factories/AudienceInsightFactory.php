<?php

namespace Database\Factories;

use App\Models\AudienceInsight;
use App\Models\Client;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AudienceInsight>
 */
class AudienceInsightFactory extends Factory
{
    protected $model = AudienceInsight::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'platform_id' => Platform::factory(),
            'source' => AudienceInsight::SOURCE_API,
            'demographic_type' => AudienceInsight::TYPE_SUMMARY,
            'snapshot_date' => now()->toDateString(),
            'follower_count' => $this->faker->numberBetween(500, 50000),
        ];
    }
}
