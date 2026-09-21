<?php

namespace Tests\Unit\Admin;

use App\Models\User;
use App\Services\Admin\AdminStaffService;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use ReflectionMethod;
use Tests\TestCase;

class AdminStaffLastSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_assert_not_last_effective_super_admin_blocks_when_alone(): void
    {
        $super = User::factory()->admin()->create();
        $service = app(AdminStaffService::class);

        $method = new ReflectionMethod(AdminStaffService::class, 'assertNotLastEffectiveSuperAdmin');
        $method->setAccessible(true);

        try {
            $method->invoke($service, $super->id);
            $this->fail('Expected HttpResponseException');
        } catch (HttpResponseException $exception) {
            $this->assertSame(422, $exception->getResponse()->getStatusCode());
            $payload = json_decode($exception->getResponse()->getContent(), true);
            $this->assertSame(ApiErrorCode::BUSINESS_VALIDATION, $payload['error']['code']);
        }
    }

    public function test_assert_not_last_effective_super_admin_allows_when_another_exists(): void
    {
        $super = User::factory()->admin()->create();
        User::factory()->admin()->create();
        $service = app(AdminStaffService::class);

        $method = new ReflectionMethod(AdminStaffService::class, 'assertNotLastEffectiveSuperAdmin');
        $method->setAccessible(true);
        $method->invoke($service, $super->id);

        $this->assertTrue(true);
    }
}
