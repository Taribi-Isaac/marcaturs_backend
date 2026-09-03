<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_business_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role', Role::Business->value)
            ->assertJsonPath('data.user.status', AccountStatus::Active->value)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.password');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('users', [
            'email' => 'biz@example.com',
            'role' => Role::Business->value,
            'status' => AccountStatus::Active->value,
        ]);

        $user = User::query()->where('email', 'biz@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertNotSame('password123', $user->getRawOriginal('password'));
    }

    public function test_an_ambassador_can_register(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'ambassador@example.com',
            'role' => 'AMBASSADOR',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.user.role', Role::Ambassador->value);
    }

    public function test_registration_rejects_invalid_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'not-an-email']))
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'biz@example.com']);

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_registration_requires_name_email_password_and_role(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
            ->assertJsonStructure(['error' => ['details' => ['name', 'email', 'password', 'role']]]);
    }

    public function test_registration_rejects_short_passwords(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_registration_rejects_self_registration_as_admin(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'role' => 'ADMIN',
        ]))
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->assertDatabaseMissing('users', ['email' => 'biz@example.com']);
        $this->assertDatabaseMissing('users', ['role' => Role::Admin->value]);
    }

    public function test_role_in_the_payload_cannot_escalate_a_business_registration(): void
    {
        $this->postJson('/api/v1/auth/register', array_merge($this->payload(), [
            'role' => 'BUSINESS',
            'is_admin' => true,
            'status' => 'banned',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.user.role', Role::Business->value)
            ->assertJsonPath('data.user.status', AccountStatus::Active->value);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Business User',
            'email' => 'biz@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'BUSINESS',
        ], $overrides);
    }
}
