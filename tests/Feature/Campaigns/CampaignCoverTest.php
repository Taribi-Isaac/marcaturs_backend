<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignCover;
use App\Models\CampaignMarketingResource;
use App\Models\CampaignVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignCoverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('campaigns.media_disk'));
    }

    public function test_owner_can_upload_show_download_replace_and_delete_cover(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $upload = $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
        ], ['Accept' => 'application/json']);

        $upload->assertCreated()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.original_filename', 'cover.jpg')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $cover = CampaignCover::query()->where('campaign_id', $campaign->id)->firstOrFail();
        Storage::disk((string) config('campaigns.media_disk'))->assertExists($cover->path);
        $this->assertSame(1, CampaignCover::query()->where('campaign_id', $campaign->id)->count());

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/cover')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.original_filename', 'cover.jpg');

        $this->get('/api/v1/campaigns/'.$campaign->id.'/cover/download', [
            'Accept' => 'application/json',
        ])->assertOk();

        $oldPath = $cover->path;
        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('replacement.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.original_filename', 'replacement.png');

        $this->assertSame(1, CampaignCover::query()->where('campaign_id', $campaign->id)->count());
        $replacement = CampaignCover::query()->where('campaign_id', $campaign->id)->firstOrFail();
        Storage::disk((string) config('campaigns.media_disk'))->assertExists($replacement->path);
        Storage::disk((string) config('campaigns.media_disk'))->assertMissing($oldPath);

        $this->deleteJson('/api/v1/campaigns/'.$campaign->id.'/cover')->assertOk();
        $this->assertDatabaseMissing('campaign_covers', ['campaign_id' => $campaign->id]);
        Storage::disk((string) config('campaigns.media_disk'))->assertMissing($replacement->path);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => CampaignStatus::Draft->value,
        ]);
    }

    public function test_failed_replacement_preserves_existing_cover(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('keep.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $existing = CampaignCover::query()->where('campaign_id', $campaign->id)->firstOrFail();
        $existingPath = $existing->path;

        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->create('bad.exe', 20, 'application/x-msdownload'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        $this->assertDatabaseHas('campaign_covers', [
            'id' => $existing->id,
            'path' => $existingPath,
        ]);
        Storage::disk((string) config('campaigns.media_disk'))->assertExists($existingPath);
        $this->assertSame(1, CampaignCover::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_invalid_mime_oversized_and_svg_are_rejected(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->create('icon.svg', 10, 'image/svg+xml'),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        config(['campaigns.max_resource_kilobytes' => 1]);

        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('huge.jpg')->size(20),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        $this->assertDatabaseMissing('campaign_covers', ['campaign_id' => $campaign->id]);
    }

    public function test_cross_business_idor_and_role_rules_block_mutation(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);
        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $intruder = User::factory()->business()->create();
        BusinessProfile::factory()->for($intruder)->create();
        Sanctum::actingAs($intruder);

        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('stolen.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/cover')->assertStatus(403);
        $this->deleteJson('/api/v1/campaigns/'.$campaign->id.'/cover')->assertStatus(403);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('no.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('guest.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(401);

        $this->assertSame(1, CampaignCover::query()->where('campaign_id', $campaign->id)->count());
    }

    public function test_public_cover_stream_requires_discoverable_campaign_with_cover(): void
    {
        $campaign = $this->discoverableCampaign();
        Sanctum::actingAs($campaign->user);
        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('public.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.cover_image.available', true)
            ->assertJsonPath('data.cover_image.url', url('/api/v1/marketplace/campaigns/'.$campaign->id.'/cover'))
            ->assertJsonMissingPath('data.cover_image.path')
            ->assertJsonMissingPath('data.cover_image.original_filename');

        $this->getJson('/api/v1/marketplace/campaigns')
            ->assertOk()
            ->assertJsonPath('data.0.cover_image.available', true);

        $this->get('/api/v1/marketplace/campaigns/'.$campaign->id.'/cover')
            ->assertOk();

        $hidden = $this->discoverableCampaign(['status' => CampaignStatus::Suspended]);
        Sanctum::actingAs($hidden->user);
        $this->post('/api/v1/campaigns/'.$hidden->id.'/cover', [
            'file' => UploadedFile::fake()->image('hidden.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->get('/api/v1/marketplace/campaigns/'.$hidden->id.'/cover')->assertStatus(404);
        $this->getJson('/api/v1/marketplace/campaigns/'.$hidden->id)->assertStatus(404);

        $draftOnly = $this->ownedCampaign()[1];
        Sanctum::actingAs($draftOnly->user);
        $this->post('/api/v1/campaigns/'.$draftOnly->id.'/cover', [
            'file' => UploadedFile::fake()->image('draft.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->get('/api/v1/marketplace/campaigns/'.$draftOnly->id.'/cover')->assertStatus(404);

        $this->get('/api/v1/marketplace/campaigns/999999/cover')->assertStatus(404);
    }

    public function test_discoverable_campaign_without_cover_returns_unavailable_and_404_stream(): void
    {
        $campaign = $this->discoverableCampaign();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.cover_image.available', false)
            ->assertJsonPath('data.cover_image.url', null);

        $this->get('/api/v1/marketplace/campaigns/'.$campaign->id.'/cover')->assertStatus(404);
    }

    public function test_cover_change_does_not_create_version_or_alter_commercial_state(): void
    {
        $campaign = $this->discoverableCampaign();
        $versionId = $campaign->current_campaign_version_id;
        $versionNumber = $campaign->currentVersion->version_number;
        $versionsBefore = CampaignVersion::query()->where('campaign_id', $campaign->id)->count();

        Sanctum::actingAs($campaign->user);
        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('cover2.png'),
        ], ['Accept' => 'application/json'])->assertOk();

        $campaign->refresh();
        $this->assertSame($versionId, $campaign->current_campaign_version_id);
        $this->assertSame($versionNumber, $campaign->currentVersion->version_number);
        $this->assertSame($versionsBefore, CampaignVersion::query()->where('campaign_id', $campaign->id)->count());
        $this->assertSame(CampaignStatus::Active, $campaign->status);
    }

    public function test_cover_deletion_leaves_marketing_resources_and_campaign_intact(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $resourceId = $this->post('/api/v1/campaigns/'.$campaign->id.'/resources', [
            'type' => 'image',
            'title' => 'Flyer',
            'file' => UploadedFile::fake()->image('flyer.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('cover.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->deleteJson('/api/v1/campaigns/'.$campaign->id.'/cover')->assertOk();

        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
        $this->assertDatabaseHas('campaign_marketing_resources', ['id' => $resourceId]);
        $this->assertDatabaseMissing('campaign_covers', ['campaign_id' => $campaign->id]);
        $this->assertNotNull(CampaignMarketingResource::query()->find($resourceId));
    }

    public function test_featured_marketplace_card_uses_same_campaign_cover(): void
    {
        $campaign = $this->discoverableCampaign();
        $campaign->forceFill(['is_featured' => true])->save();

        Sanctum::actingAs($campaign->user);
        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('featured-cover.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/marketplace/campaigns?featured=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $campaign->id)
            ->assertJsonPath('data.0.is_featured', true)
            ->assertJsonPath('data.0.cover_image.available', true)
            ->assertJsonPath('data.0.cover_image.url', url('/api/v1/marketplace/campaigns/'.$campaign->id.'/cover'));
    }

    public function test_admin_can_show_and_download_cover_for_any_campaign(): void
    {
        $campaign = $this->discoverableCampaign(['status' => CampaignStatus::Suspended]);
        Sanctum::actingAs($campaign->user);
        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('admin.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id.'/cover')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonMissingPath('data.path');

        $this->get('/api/v1/admin/campaigns/'.$campaign->id.'/cover/download')
            ->assertOk();

        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.cover_image.available', true)
            ->assertJsonMissingPath('data.cover_image.path');
    }

    public function test_business_campaign_payload_includes_cover_without_storage_keys(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.cover_image.available', false);

        $this->post('/api/v1/campaigns/'.$campaign->id.'/cover', [
            'file' => UploadedFile::fake()->image('owned.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->getJson('/api/v1/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.cover_image.available', true)
            ->assertJsonPath('data.cover_image.original_filename', 'owned.jpg')
            ->assertJsonMissingPath('data.cover_image.path')
            ->assertJsonMissingPath('data.cover_image.disk');
    }

    /**
     * @return array{0: User, 1: Campaign}
     */
    private function ownedCampaign(): array
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create();

        return [$owner, $campaign];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function discoverableCampaign(array $attributes = []): Campaign
    {
        $owner = User::factory()->business()->create();
        BusinessProfile::factory()->for($owner)->create();
        $campaign = Campaign::factory()->for($owner)->create(array_merge([
            'status' => CampaignStatus::Active,
            'listing_starts_at' => now(),
            'listing_expires_at' => now()->addDays(30),
        ], $attributes));
        $version = CampaignVersion::factory()->for($campaign)->published()->create();
        $campaign->forceFill(['current_campaign_version_id' => $version->id])->save();

        return $campaign->fresh(['currentVersion', 'user']);
    }
}
