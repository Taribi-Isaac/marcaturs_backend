<?php

namespace Tests\Unit;

use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_has_at_most_one_business_profile(): void
    {
        $user = User::factory()->business()->create();
        BusinessProfile::factory()->for($user)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        BusinessProfile::factory()->for($user)->create([
            'legal_name' => 'Second Profile',
        ]);
    }
}
