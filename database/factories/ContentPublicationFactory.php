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
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_item_id' => ContentItem::factory(),
            'platform_id' => fn () => Platform::firstOrCreate(['name' => 'Instagram'])->id,
            'published_by' => User::factory(),
            'published_at' => now(),
        ];
    }
}
