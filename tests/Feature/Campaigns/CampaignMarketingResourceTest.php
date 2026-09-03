<?php

namespace Tests\Feature\Campaigns;

use App\Enums\CampaignStatus;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignMarketingResource;
use App\Models\CampaignVersion;
use App\Models\User;
use App\Support\Api\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignMarketingResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('campaigns.media_disk'));
    }

    public function test_owner_can_upload_list_download_replace_and_delete_resource(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $upload = $this->post('/api/v1/campaigns/'.$campaign->id.'/resources', [
            'type' => 'image',
            'title' => 'Hero image',
            'description' => 'Storefront photo',
            'file' => UploadedFile::fake()->image('store.jpg'),
        ], ['Accept' => 'application/json']);

        $upload->assertCreated()
            ->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.title', 'Hero image')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $id = $upload->json('data.id');
        Storage::disk((string) config('campaigns.media_disk'))->assertExists(
            CampaignMarketingResource::query()->findOrFail($id)->path,
        );

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/resources')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.original_filename', 'store.jpg');

        $this->get('/api/v1/campaigns/'.$campaign->id.'/resources/'.$id.'/download', [
            'Accept' => 'application/json',
        ])->assertOk();

        $this->patch('/api/v1/campaigns/'.$campaign->id.'/resources/'.$id, [
            'title' => 'Updated flyer',
            'file' => UploadedFile::fake()->image('updated.png'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated flyer');

        $this->deleteJson('/api/v1/campaigns/'.$campaign->id.'/resources/'.$id)
            ->assertOk();
        $this->assertDatabaseMissing('campaign_marketing_resources', ['id' => $id]);
    }

    public function test_invalid_mime_and_oversized_files_are_rejected(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);

        $this->post('/api/v1/campaigns/'.$campaign->id.'/resources', [
            'type' => 'image',
            'title' => 'Bad file',
            'file' => UploadedFile::fake()->create('payload.exe', 20, 'application/x-msdownload'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', ApiErrorCode::VALIDATION_ERROR);

        config(['campaigns.max_resource_kilobytes' => 1]);

        $this->post('/api/v1/campaigns/'.$campaign->id.'/resources', [
            'type' => 'document',
            'title' => 'Huge pdf',
            'file' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(400);
    }

    public function test_cross_business_idor_and_role_rules(): void
    {
        [$owner, $campaign] = $this->ownedCampaign();
        Sanctum::actingAs($owner);
        $id = $this->post('/api/v1/campaigns/'.$campaign->id.'/resources', [
            'type' => 'flyer',
            'title' => 'Flyer',
            'file' => UploadedFile::fake()->image('flyer.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $intruder = User::factory()->business()->create();
        BusinessProfile::factory()->for($intruder)->create();
        Sanctum::actingAs($intruder);
        $this->post('/api/v1/campaigns/'.$campaign->id.'/resources', [
            'type' => 'image',
            'title' => 'Stolen',
            'file' => UploadedFile::fake()->image('x.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->getJson('/api/v1/campaigns/'.$campaign->id.'/resources/'.$id)->assertStatus(403);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->post('/api/v1/campaigns/'.$campaign->id.'/resources', [
            'type' => 'image',
            'title' => 'No',
            'file' => UploadedFile::fake()->image('x.jpg'),
        ], ['Accept' => 'application/json'])->assertStatus(403);
    }

    public function test_marketplace_lists_metadata_and_ambassador_can_download_when_discoverable(): void
    {
        $campaign = $this->discoverableCampaign();
        $resource = $this->attachResource($campaign);
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id)
            ->assertOk()
            ->assertJsonPath('data.marketing_resources.0.id', $resource->id)
            ->assertJsonPath('data.marketing_resources.0.title', 'Hero image')
            ->assertJsonMissingPath('data.marketing_resources.0.path')
            ->assertJsonMissingPath('data.marketing_resources.0.original_filename');

        $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id.'/resources/'.$resource->id.'/download')
            ->assertStatus(401);

        Sanctum::actingAs(User::factory()->ambassador()->create());
        $this->get('/api/v1/marketplace/campaigns/'.$campaign->id.'/resources/'.$resource->id.'/download')
            ->assertOk();

        Sanctum::actingAs(User::factory()->business()->create());
        $this->get('/api/v1/marketplace/campaigns/'.$campaign->id.'/resources/'.$resource->id.'/download')
            ->assertStatus(403);
    }

    public function test_ambassador_cannot_download_resources_for_hidden_campaign_states(): void
    {
        $campaign = $this->discoverableCampaign(['status' => CampaignStatus::Deactivated]);
        $resource = $this->attachResource($campaign);
        Sanctum::actingAs(User::factory()->ambassador()->create());

        $this->get('/api/v1/marketplace/campaigns/'.$campaign->id.'/resources/'.$resource->id.'/download')
            ->assertStatus(404);

        $this->getJson('/api/v1/marketplace/campaigns/'.$campaign->id)->assertStatus(404);
    }

    public function test_admin_can_list_and_download_any_campaign_resources(): void
    {
        $campaign = $this->discoverableCampaign(['status' => CampaignStatus::Suspended]);
        $resource = $this->attachResource($campaign);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/admin/campaigns/'.$campaign->id.'/resources')
            ->assertOk()
            ->assertJsonPath('data.0.id', $resource->id);

        $this->get('/api/v1/admin/campaigns/'.$campaign->id.'/resources/'.$resource->id.'/download')
            ->assertOk();
    }

    public function test_expired_resources_remain_and_become_downloadable_after_reactivation(): void
    {
        $campaign = $this->discoverableCampaign(['status' => CampaignStatus::Expired]);
        $resource = $this->attachResource($campaign);
        $ambassador = User::factory()->ambassador()->create();
        Sanctum::actingAs($ambassador);

        $this->get('/api/v1/marketplace/campaigns/'.$campaign->id.'/resources/'.$resource->id.'/download')
            ->assertStatus(404);

        $campaign->forceFill(['status' => CampaignStatus::Active])->save();

        $this->get('/api/v1/marketplace/campaigns/'.$campaign->id.'/resources/'.$resource->id.'/download')
            ->assertOk();
        $this->assertDatabaseHas('campaign_marketing_resources', ['id' => $resource->id]);
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

        return $campaign->fresh();
    }

    private function attachResource(Campaign $campaign): CampaignMarketingResource
    {
        Sanctum::actingAs($campaign->user);
        $id = $this->post('/api/v1/campaigns/'.$campaign->id.'/resources', [
            'type' => 'image',
            'title' => 'Hero image',
            'file' => UploadedFile::fake()->image('store.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        return CampaignMarketingResource::query()->findOrFail($id);
    }
}
