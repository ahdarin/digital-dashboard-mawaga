<?php

namespace Database\Factories;

use App\Models\ContentBriefDraft;
use App\Models\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentBriefDraft>
 */
class ContentBriefDraftFactory extends Factory
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
            'status' => 'finalized',
        ];
    }
}
