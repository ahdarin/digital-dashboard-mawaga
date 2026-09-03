<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ContentItem;
use App\Models\ContentMetricSnapshot;
use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentMetricSnapshot>
 */
class ContentMetricSnapshotFactory extends Factory
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
            'platform_id' => fn () => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'content_item_id' => ContentItem::factory(),
            'snapshot_date' => now(),
            'views' => 1000,
            'engagement_rate' => 5.0,
        ];
    }
}
