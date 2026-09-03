<?php

namespace Tests\Feature;

use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ApplicationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_boots_in_the_isolated_test_environment(): void
    {
        $this->assertTrue(app()->isBooted());
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_the_test_database_is_reachable_and_migrated(): void
    {
        $this->assertNotNull(DB::connection()->getPdo());
        $this->assertTrue(Schema::hasTable('migrations'));
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertTrue(Schema::hasTable('campaign_marketing_resources'));
        $this->assertTrue(Schema::hasTable('conversations'));
        $this->assertTrue(Schema::hasTable('conversation_participants'));
        $this->assertTrue(Schema::hasTable('messages'));
        $this->assertTrue(Schema::hasTable('deals'));
        $this->assertTrue(Schema::hasTable('deal_events'));
        $this->assertTrue(Schema::hasTable('payment_evidence'));
        $this->assertTrue(Schema::hasTable('commissions'));
        $this->assertTrue(Schema::hasTable('commission_events'));
    }

    public function test_success_and_paginated_responses_use_the_canonical_envelope(): void
    {
        $success = ApiResponse::success(['ok' => true])->getData(true);

        $this->assertTrue($success['success']);
        $this->assertSame(['ok' => true], $success['data']);

        $paginator = new LengthAwarePaginator(
            [['id' => 1]],
            1,
            15,
            1,
        );

        $paginated = ApiResponse::paginated($paginator)->getData(true);

        $this->assertTrue($paginated['success']);
        $this->assertSame(['current_page', 'per_page', 'total', 'last_page', 'from', 'to'], array_keys($paginated['meta']['pagination']));
    }

    public function test_api_error_contract_covers_foundation_status_codes(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(function (): void {
            Route::post('/__foundation/validate', function () {
                request()->validate(['email' => 'required|email']);
            });

            Route::get('/__foundation/unauthenticated', fn () => abort(401));
            Route::get('/__foundation/forbidden', fn () => abort(403));
            Route::get('/__foundation/conflict', fn () => abort(409, 'State conflict'));
            Route::get('/__foundation/business-validation', fn () => abort(422, 'Business rule failed'));
            Route::get('/__foundation/unavailable', fn () => abort(503));
            Route::get('/__foundation/server-error', fn () => abort(500));
            Route::get('/__foundation/rate-limited', fn () => response()->json(['ok' => true]))
                ->middleware('throttle:1,1');
        });

        $this->postJson('/api/v1/__foundation/validate', [])
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR)
            ->assertJsonStructure(['error' => ['code', 'message', 'details']]);

        $this->getJson('/api/v1/__foundation/unauthenticated')
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);

        $this->getJson('/api/v1/__foundation/forbidden')
            ->assertStatus(403)
            ->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->getJson('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('error.code', ApiErrorCode::NOT_FOUND);

        $this->getJson('/api/v1/__foundation/conflict')
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::CONFLICT);

        $this->getJson('/api/v1/__foundation/business-validation')
            ->assertStatus(422)
            ->assertJsonPath('error.code', ApiErrorCode::BUSINESS_VALIDATION);

        $this->getJson('/api/v1/__foundation/unavailable')
            ->assertStatus(503)
            ->assertJsonPath('error.code', ApiErrorCode::SERVICE_UNAVAILABLE);

        $this->getJson('/api/v1/__foundation/server-error')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', ApiErrorCode::SERVER_ERROR)
            ->assertJsonMissingPath('error.trace')
            ->assertJsonMissingPath('data.debug');

        $this->getJson('/api/v1/__foundation/rate-limited')->assertSuccessful();
        $this->getJson('/api/v1/__foundation/rate-limited')
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiErrorCode::RATE_LIMITED);
    }
}
