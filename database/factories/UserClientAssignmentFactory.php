<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use App\Models\UserClientAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserClientAssignment>
 */
class UserClientAssignmentFactory extends Factory
{
    protected $model = UserClientAssignment::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
        ];
    }
}
