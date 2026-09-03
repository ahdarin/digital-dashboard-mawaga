<?php

namespace Database\Factories;

use App\Models\ContentItem;
use App\Models\ContentItemAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentItemAssignment>
 */
class ContentItemAssignmentFactory extends Factory
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
            'user_id' => User::factory(),
            'assignment_role' => 'primary',
        ];
    }
}
