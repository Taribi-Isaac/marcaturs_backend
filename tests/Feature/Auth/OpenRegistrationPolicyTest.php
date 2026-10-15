<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Support\Api\ApiErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MH-OPS-032: open BUSINESS/AMBASSADOR registration, mandatory email verification,
 * one account per email across roles, no self-assigned privileges.
 */
class OpenRegistrationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['api.rate_limits.registration_per_minute' => 100]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function publicRoleProvider(): array
    {
        return [
            'business' => [Role::Business->value],
            'ambassador' => [Role::Ambassador->value],
        ];
    }

    #[DataProvider('publicRoleProvider')]
    public function test_a_guest_registers_without_any_pre_approval_and_starts_unverified(string $role): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(Schema::hasTable('registration_approvals'));
        $this->assertNull(config('registration'));

        $this->postJson('/api/v1/auth/register', $this->payload(['role' => $role]))
            ->assertCreated()
            ->assertJsonPath('data.user.role', $role)
            ->assertJsonPath('data.user.status', AccountStatus::Active->value)
            ->assertJsonPath('data.user.email_verified_at', null);

        $user = User::query()->where('email', 'new.participant@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame($role, $user->role->value);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
        Notification::assertCount(1);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidRoleProvider(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'unknown' => ['CUSTOMER'],
            'array' => [['BUSINESS']],
            'admin' => ['ADMIN'],
            'admin lowercase' => ['admin'],
            'super admin' => ['SUPER_ADMIN'],
            'operations' => ['OPERATIONS'],
            'verification staff' => ['VERIFICATION'],
            'moderation staff' => ['MODERATION'],
            'combined' => ['BUSINESS,ADMIN'],
        ];
    }

    #[DataProvider('invalidRoleProvider')]
    public function test_missing_invalid_and_privileged_roles_are_rejected(mixed $role): void
    {
        $payload = $this->payload();
        if ($role === null) {
            unset($payload['role']);
        } else {
            $payload['role'] = $role;
        }

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
            ->assertJsonStructure(['error' => ['details' => ['role']]]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_client_supplied_privilege_and_verification_fields_are_ignored(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'role' => 'AMBASSADOR',
            'email_verified_at' => now()->toIso8601String(),
            'status' => 'banned',
            'staff_role' => 'SUPER_ADMIN',
            'permissions' => ['staff.manage', 'users.manage'],
            'is_admin' => true,
            'id' => 1,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.user.role', Role::Ambassador->value)
            ->assertJsonPath('data.user.status', AccountStatus::Active->value)
            ->assertJsonPath('data.user.email_verified_at', null)
            ->assertJsonMissingPath('data.user.staff_role')
            ->assertJsonMissingPath('data.user.permissions');

        $user = User::query()->where('email', 'new.participant@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->isAdmin());
        $this->assertDatabaseCount('admin_staff_profiles', 0);

        $this->withToken($this->tokenFor($user))->getJson('/api/v1/admin/overview')->assertStatus(403);
    }

    public function test_the_emailed_verification_link_verifies_only_the_intended_account(): void
    {
        $other = User::factory()->unverified()->business()->create();

        $this->postJson('/api/v1/auth/register', $this->payload(['role' => 'BUSINESS']))->assertCreated();
        $user = User::query()->where('email', 'new.participant@example.com')->firstOrFail();

        $url = null;
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user, &$url): bool {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });
        $this->assertIsString($url);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertNotNull($user->fresh()?->email_verified_at);
        $this->assertNull($other->fresh()?->email_verified_at);

        $verifiedAt = $user->fresh()?->email_verified_at?->toIso8601String();
        $this->getJson($url)->assertOk();
        $this->assertSame($verifiedAt, $user->fresh()?->email_verified_at?->toIso8601String());

        $tampered = str_replace('/verify/'.$user->id.'/', '/verify/'.$other->id.'/', $url);
        $this->getJson($tampered)->assertStatus(403);
        $this->assertNull($other->fresh()?->email_verified_at);
    }

    /**
     * @return array<string, array{0: string, 1: list<array{0: string, 1: string}>}>
     */
    public static function protectedRouteProvider(): array
    {
        return [
            'business' => ['BUSINESS', [
                ['GET', '/api/v1/businesses/me'],
                ['POST', '/api/v1/businesses/me'],
                ['GET', '/api/v1/campaigns'],
                ['POST', '/api/v1/campaigns'],
                ['GET', '/api/v1/deals'],
                ['GET', '/api/v1/commissions'],
                ['GET', '/api/v1/conversations'],
                ['GET', '/api/v1/notifications'],
                ['GET', '/api/v1/verification/status'],
                ['POST', '/api/v1/verification/submissions'],
            ]],
            'ambassador' => ['AMBASSADOR', [
                ['GET', '/api/v1/ambassadors/me'],
                ['POST', '/api/v1/ambassadors/me'],
                ['POST', '/api/v1/deals'],
                ['GET', '/api/v1/deals'],
                ['GET', '/api/v1/commissions'],
                ['GET', '/api/v1/conversations'],
                ['POST', '/api/v1/conversations'],
                ['GET', '/api/v1/notifications'],
                ['GET', '/api/v1/verification/status'],
                ['POST', '/api/v1/verification/submissions'],
            ]],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $routes
     */
    #[DataProvider('protectedRouteProvider')]
    public function test_unverified_participants_cannot_call_protected_apis_directly_until_verified(string $role, array $routes): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->payload(['role' => $role]))
            ->assertCreated()
            ->json('data.token');

        foreach ($routes as [$method, $uri]) {
            $this->withToken($token)->json($method, $uri, ['email_verified_at' => now()->toIso8601String()])
                ->assertStatus(403)
                ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN)
                ->assertJsonPath('error.message', 'Please verify your email address to continue.');
        }

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified_at', null);
        $this->withToken($token)->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.already_verified', false);

        $user = User::query()->where('email', 'new.participant@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);

        $user->markEmailAsVerified();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/verification/status')->assertOk();
        $this->withToken($token)->getJson('/api/v1/deals')->assertOk();
    }

    public function test_change_password_and_logout_remain_available_before_verification(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated()->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password123',
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function duplicateProvider(): array
    {
        return [
            'business then ambassador' => ['BUSINESS', 'AMBASSADOR', 'taken@example.com'],
            'ambassador then business' => ['AMBASSADOR', 'BUSINESS', 'taken@example.com'],
            'same role business' => ['BUSINESS', 'BUSINESS', 'taken@example.com'],
            'same role ambassador' => ['AMBASSADOR', 'AMBASSADOR', 'taken@example.com'],
            'case and whitespace variant' => ['BUSINESS', 'AMBASSADOR', "  TAKEN@Example.COM\t"],
        ];
    }

    #[DataProvider('duplicateProvider')]
    public function test_an_email_can_hold_only_one_account_across_roles(string $firstRole, string $secondRole, string $secondEmail): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'name' => 'Original Holder',
            'email' => 'taken@example.com',
            'role' => $firstRole,
        ]))->assertCreated();

        $response = $this->postJson('/api/v1/auth/register', $this->payload([
            'name' => 'Second Attempt',
            'email' => $secondEmail,
            'role' => $secondRole,
        ]))
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
            ->assertJsonPath('error.details.email.0', 'The email has already been taken.');

        $this->assertSame(['email'], array_keys($response->json('error.details')));
        $body = (string) $response->getContent();
        foreach (['Original Holder', $firstRole, 'active', 'email_verified_at', 'user'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }

        $this->assertSame(1, User::query()->count());
        $this->assertSame($firstRole, User::query()->firstOrFail()->role->value);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        Notification::assertCount(1);
    }

    public function test_a_duplicate_that_slips_past_validation_returns_the_same_error_and_creates_nothing(): void
    {
        // Simulate a concurrent request inserting the same email between validation and this insert.
        $raced = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$raced): void {
            if ($raced || preg_match('/^insert into [`"]?users[`"]? /i', $query) !== 1) {
                return;
            }

            $raced = true;
            DB::table('users')->insert([
                'name' => 'Concurrent Winner',
                'email' => 'concurrent@example.com',
                'password' => 'not-a-real-hash',
                'role' => Role::Ambassador->value,
                'status' => AccountStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->postJson('/api/v1/auth/register', $this->payload([
            'name' => 'Concurrent Loser',
            'email' => 'concurrent@example.com',
            'role' => 'BUSINESS',
        ]));

        $this->assertTrue($raced);
        $response->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
            ->assertJsonPath('error.details.email.0', 'The email has already been taken.');
        $this->assertStringNotContainsStringIgnoringCase('sqlstate', (string) $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('constraint', (string) $response->getContent());

        $this->assertSame(1, User::query()->where('email', 'concurrent@example.com')->count());
        $this->assertSame('Concurrent Winner', User::query()->where('email', 'concurrent@example.com')->value('name'));
        $this->assertSame(1, User::query()->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertGuest('web');
        Notification::assertNothingSent();
    }

    public function test_the_database_rejects_a_second_row_for_the_same_email(): void
    {
        User::factory()->business()->create(['email' => 'taken@example.com']);

        $this->expectException(UniqueConstraintViolationException::class);

        User::factory()->ambassador()->create(['email' => 'taken@example.com']);
    }

    public function test_registration_and_login_do_not_change_existing_verification_states(): void
    {
        $verified = User::factory()->business()->create(['email' => 'verified@example.com', 'password' => 'password123']);
        $unverified = User::factory()->unverified()->ambassador()->create(['email' => 'unverified@example.com', 'password' => 'password123']);
        $verifiedAt = $verified->email_verified_at?->toIso8601String();

        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->assertNotNull(
            $this->postJson('/api/v1/auth/login', ['email' => 'verified@example.com', 'password' => 'password123'])
                ->assertOk()
                ->json('data.user.email_verified_at'),
        );
        $unverifiedToken = $this->postJson('/api/v1/auth/login', ['email' => 'UNVERIFIED@example.com ', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('data.user.email_verified_at', null)
            ->json('data.token');

        $this->withToken($unverifiedToken)->getJson('/api/v1/ambassadors/me')->assertStatus(403);

        $this->assertSame($verifiedAt, $verified->fresh()?->email_verified_at?->toIso8601String());
        $this->assertNull($unverified->fresh()?->email_verified_at);
        $this->assertSame(Role::Business, $verified->fresh()?->role);
        $this->assertSame(Role::Ambassador, $unverified->fresh()?->role);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Participant',
            'email' => 'new.participant@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'BUSINESS',
        ], $overrides);
    }
}
