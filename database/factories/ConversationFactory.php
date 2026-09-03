<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_user_id' => User::factory()->business(),
            'ambassador_user_id' => User::factory()->ambassador(),
        ];
    }
}
