<?php

namespace Database\Factories;

use App\Models\ContentItem;
use App\Models\ContentStatusLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentStatusLog>
 */
class ContentStatusLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'from_status' => 'brief_ready',
            'to_status' => 'in_progress',
            'changed_at' => now(),
        ];
    }
}
