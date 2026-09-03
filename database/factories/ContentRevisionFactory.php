<?php

namespace Database\Factories;

use App\Models\ContentItem;
use App\Models\ContentRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentRevision>
 */
class ContentRevisionFactory extends Factory
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
            'revision_round' => 1,
            'revision_note' => $this->faker->sentence(),
            'status' => 'open',
        ];
    }
}
