<?php

namespace Tests\Feature\Development;

use App\Enums\AccountStatus;
use App\Enums\CampaignStatus;
use App\Enums\CommissionStatus;
use App\Enums\DealStatus;
use App\Enums\DisputeStatus;
use App\Enums\Role;
use App\Models\Campaign;
use App\Models\CampaignFeaturedPurchase;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Dispute;
use App\Models\Message;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Support\Development\DevelopmentSeedGuard;
use Database\Seeders\DevelopmentScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DevelopmentScenarioSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_development_scenario_seeds_successfully_on_clean_database(): void
    {
        $this->seed(DevelopmentScenarioSeeder::class);

        $this->assertSame(
            2,
            User::query()->where('role', Role::Admin)->where('email', 'like', '%@demo.marcaturshub.test')->count(),
        );
        $this->assertSame(
            5,
            User::query()->where('role', Role::Business)->where('email', 'like', '%@demo.marcaturshub.test')->count(),
        );
        $this->assertSame(
            5,
            User::query()->where('role', Role::Ambassador)->where('email', 'like', '%@demo.marcaturshub.test')->count(),
        );
        $this->assertSame(8, Category::query()->where('slug', 'like', 'demo-%')->count());
        $this->assertGreaterThanOrEqual(9, Campaign::query()->where('title', 'like', 'Demo %')->count());
        $this->assertGreaterThanOrEqual(5, Deal::query()->count());
        $this->assertGreaterThanOrEqual(4, Commission::query()->count());
        $this->assertSame(6, Dispute::query()->where('reference', 'like', 'MH-D-DEMO%')->count());
        $this->assertSame(2, Conversation::query()->count());
        $this->assertGreaterThanOrEqual(4, Message::query()->count());
        $this->assertGreaterThanOrEqual(3, CampaignFeaturedPurchase::query()->count());
        $this->assertGreaterThanOrEqual(4, PlatformPayment::query()->count());
    }

    public function test_development_scenario_is_repeatable_without_duplicate_demo_users(): void
    {
        $this->seed(DevelopmentScenarioSeeder::class);
        $firstUserCount = User::query()->where('email', 'like', '%@demo.marcaturshub.test')->count();
        $firstDealCount = Deal::query()->count();
        $firstDisputeCount = Dispute::query()->where('reference', 'like', 'MH-D-DEMO%')->count();

        $this->seed(DevelopmentScenarioSeeder::class);

        $this->assertSame(
            $firstUserCount,
            User::query()->where('email', 'like', '%@demo.marcaturshub.test')->count(),
        );
        $this->assertSame($firstDealCount, Deal::query()->count());
        $this->assertSame(
            $firstDisputeCount,
            Dispute::query()->where('reference', 'like', 'MH-D-DEMO%')->count(),
        );
        $this->assertSame(
            1,
            User::query()->where('email', 'admin.primary@demo.marcaturshub.test')->count(),
        );
    }

    public function test_major_lifecycle_and_relationship_scenarios_exist(): void
    {
        $this->seed(DevelopmentScenarioSeeder::class);

        foreach ([
            CampaignStatus::Draft,
            CampaignStatus::Submitted,
            CampaignStatus::Approved,
            CampaignStatus::Active,
            CampaignStatus::Expiring,
            CampaignStatus::Expired,
            CampaignStatus::Deactivated,
            CampaignStatus::Suspended,
            CampaignStatus::Closed,
        ] as $status) {
            $this->assertTrue(
                Campaign::query()->where('title', 'like', 'Demo %')->where('status', $status)->exists(),
                'Missing campaign status '.$status->value,
            );
        }

        foreach ([DealStatus::PaymentPending, DealStatus::Sealed, DealStatus::Completed, DealStatus::Cancelled] as $status) {
            $this->assertTrue(Deal::query()->where('status', $status)->exists(), 'Missing deal status '.$status->value);
        }

        $this->assertTrue(Commission::query()->where('status', CommissionStatus::Due)->where('due_at', '>', Carbon::parse(DevelopmentScenarioSeeder::NOW))->exists());
        $this->assertTrue(Commission::query()->where('status', CommissionStatus::Due)->where('due_at', '<', Carbon::parse(DevelopmentScenarioSeeder::NOW))->exists());
        $this->assertTrue(Commission::query()->where('status', CommissionStatus::Paid)->exists());
        $this->assertTrue(Commission::query()->where('status', CommissionStatus::Received)->exists());

        foreach ([
            DisputeStatus::Submitted,
            DisputeStatus::UnderReview,
            DisputeStatus::EvidenceRequested,
            DisputeStatus::DecisionPending,
            DisputeStatus::Resolved,
            DisputeStatus::Closed,
        ] as $status) {
            $this->assertTrue(
                Dispute::query()->where('reference', 'like', 'MH-D-DEMO%')->where('status', $status)->exists(),
                'Missing dispute status '.$status->value,
            );
        }

        $featured = Campaign::query()->where('title', 'Demo Solar Street Light Kits')->firstOrFail();
        $this->assertTrue($featured->is_featured);
        $this->assertSame(2, CampaignFeaturedPurchase::query()->where('campaign_id', $featured->id)->count());

        $deal = Deal::query()->where('status', DealStatus::Sealed)->firstOrFail();
        $this->assertNotNull($deal->campaign_id);
        $this->assertNotNull($deal->campaign_version_id);
        $this->assertTrue(Commission::query()->where('deal_id', $deal->id)->exists());

        $this->assertTrue(
            User::query()->where('email', 'business.edu@demo.marcaturshub.test')->where('status', AccountStatus::Restricted)->exists(),
        );
        $this->assertTrue(
            User::query()->where('email', 'ambassador.banned@demo.marcaturshub.test')->where('status', AccountStatus::Banned)->exists(),
        );

        $this->assertTrue(
            DB::table('notifications')->where('idempotency_key', 'like', 'demo:notification:%')->whereNull('read_at')->exists(),
        );
        $this->assertTrue(
            DB::table('notifications')->where('idempotency_key', 'like', 'demo:notification:%')->whereNotNull('read_at')->exists(),
        );
    }

    public function test_production_safety_guard_blocks_non_allowed_environments(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blocked');

        app()->detectEnvironment(fn () => 'production');

        DevelopmentSeedGuard::ensureAllowed(false);
    }

    public function test_artisan_seed_demo_command_runs_in_testing(): void
    {
        $this->artisan('marcaturs:seed-demo')
            ->assertSuccessful();

        $this->assertTrue(
            User::query()->where('email', 'admin.primary@demo.marcaturshub.test')->exists(),
        );
    }
}
