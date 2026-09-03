<?php

namespace Tests\Feature\Categories;

use App\Enums\CategoryListingStatus;
use App\Models\Category;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_list_returns_only_active_assignable_categories(): void
    {
        Category::factory()->create(['name' => 'Education', 'slug' => 'education', 'sort_order' => 1]);
        Category::factory()->restricted()->create(['name' => 'Real Estate', 'slug' => 'real-estate', 'sort_order' => 2]);
        Category::factory()->prohibited()->create(['name' => 'Prohibited Example', 'slug' => 'prohibited-example']);
        Category::factory()->inactive()->create(['name' => 'Inactive Example', 'slug' => 'inactive-example']);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Education')
            ->assertJsonPath('data.0.listing_status', CategoryListingStatus::Allowed->value)
            ->assertJsonPath('data.1.name', 'Real Estate')
            ->assertJsonPath('data.1.listing_status', CategoryListingStatus::Restricted->value)
            ->assertJsonMissingPath('data.0.is_active')
            ->assertJsonMissing(['name' => 'Prohibited Example'])
            ->assertJsonMissing(['name' => 'Inactive Example']);
    }

    public function test_business_and_ambassador_cannot_mutate_categories(): void
    {
        $category = Category::factory()->create();

        Sanctum::actingAs(User::factory()->business()->create());
        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Technology',
        ])->assertStatus(403)->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->patchJson('/api/v1/admin/categories/'.$category->id, [
            'is_active' => false,
        ])->assertStatus(403)->assertJsonPath('error.code', ApiErrorCode::FORBIDDEN);

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_unauthenticated_admin_mutation_is_rejected(): void
    {
        $this->postJson('/api/v1/admin/categories', ['name' => 'Technology'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', ApiErrorCode::UNAUTHENTICATED);
    }

    public function test_admin_can_create_update_and_deactivate_categories(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Professional Services',
            'description' => 'Administrator-defined category.',
            'listing_status' => CategoryListingStatus::Allowed->value,
            'sort_order' => 4,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Professional Services')
            ->assertJsonPath('data.slug', 'professional-services')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.listing_status', 'allowed');

        $category = Category::query()->firstOrFail();

        $this->getJson('/api/v1/admin/categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $category->id)
            ->assertJsonPath('data.0.is_active', true);

        $this->patchJson('/api/v1/admin/categories/'.$category->id, [
            'listing_status' => CategoryListingStatus::Restricted->value,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.listing_status', 'restricted')
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        Category::factory()->create(['name' => 'Logistics', 'slug' => 'logistics']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Logistics',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }

    public function test_invalid_listing_status_is_rejected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/admin/categories', [
            'name' => 'Construction',
            'listing_status' => 'hidden',
        ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);
    }
}
