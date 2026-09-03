<?php

namespace Database\Factories;

use App\Models\ContentItem;
use App\Models\ContentPublication;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPublication>
 */
class ContentPublicationFactory extends Factory
{
    protected $model = ContentPublication::class;

    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'platform_id' => Platform::factory(),
            'published_by' => User::factory(),
            'published_at' => now()->subDays(10),
            'post_url' => 'https://example.test/p/'.$this->faker->uuid(),
            'is_paid' => false,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'is_paid' => true,
            'promotion_type' => 'boosted_post',
            'ad_spend' => $this->faker->randomFloat(2, 50000, 500000),
        ]);
    }
}
