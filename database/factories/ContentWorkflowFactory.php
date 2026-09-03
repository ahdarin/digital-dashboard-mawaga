<?php

namespace Database\Factories;

use App\Models\ContentItem;
use App\Models\ContentWorkflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentWorkflow>
 */
class ContentWorkflowFactory extends Factory
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
            'current_status' => 'brief_ready',
            'is_overdue' => false,
        ];
    }
}
