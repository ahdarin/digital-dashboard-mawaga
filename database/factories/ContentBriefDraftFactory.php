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
    protected $model = ContentBriefDraft::class;

    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'status' => 'draft',
            'returned_count' => 0,
        ];
    }

    public function finalized(): static
    {
        return $this->state(fn () => ['status' => 'finalized', 'finalized_at' => now()]);
    }
}
