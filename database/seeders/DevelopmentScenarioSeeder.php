<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\CampaignMarketingResourceType;
use App\Enums\CampaignStatus;
use App\Enums\CampaignVersionStatus;
use App\Enums\CategoryListingStatus;
use App\Enums\CommissionEventType;
use App\Enums\CommissionStatus;
use App\Enums\CommissionTrigger;
use App\Enums\CommissionType;
use App\Enums\DealEventType;
use App\Enums\DealStatus;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\MessageType;
use App\Enums\NotificationType;
use App\Enums\PaymentEvidenceKind;
use App\Enums\PaymentEvidenceStatus;
use App\Enums\PlatformPaymentPurpose;
use App\Enums\PlatformPaymentStatus;
use App\Enums\PricingMethod;
use App\Enums\Role;
use App\Enums\VerificationRequirementType;
use App\Enums\VerificationSubmissionStatus;
use App\Models\AmbassadorProfile;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\CampaignExtension;
use App\Models\CampaignExtensionPackage;
use App\Models\CampaignFeaturedPackage;
use App\Models\CampaignFeaturedPurchase;
use App\Models\CampaignMarketingResource;
use App\Models\CampaignVersion;
use App\Models\Category;
use App\Models\Commission;
use App\Models\CommissionEvent;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Dispute;
use App\Models\DisputeCategory;
use App\Models\DisputeEvent;
use App\Models\Message;
use App\Models\PaymentEvidence;
use App\Models\PlatformPayment;
use App\Models\User;
use App\Models\VerificationRequirement;
use App\Models\VerificationSubmission;
use App\Notifications\CampaignFeaturedPurchasedNotification;
use App\Notifications\CommissionNotification;
use App\Notifications\DealCancelledNotification;
use App\Notifications\DisputeNotification;
use App\Support\Development\DevelopmentSeedGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Deterministic development/UAT scenario for Admin and primary frontend work.
 *
 * Run: php artisan marcaturs:seed-demo
 *  or: php artisan db:seed --class=DevelopmentScenarioSeeder
 *
 * Password for all demo users: DemoPass123!
 */
class DevelopmentScenarioSeeder extends Seeder
{
    public const DEMO_PASSWORD = 'DemoPass123!';

    public const NOW = '2026-09-07 12:00:00';

    private bool $force = false;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Category> */
    private array $categories = [];

    /** @var array<string, Campaign> */
    private array $campaigns = [];

    /** @var array<string, CampaignVersion> */
    private array $versions = [];

    /** @var array<string, Deal> */
    private array $deals = [];

    /** @var array<string, Commission> */
    private array $commissions = [];

    /** @var array<string, CampaignFeaturedPackage> */
    private array $featuredPackages = [];

    public function force(bool $force = true): self
    {
        $this->force = $force;

        return $this;
    }

    public function run(): void
    {
        DevelopmentSeedGuard::ensureAllowed($this->force);

        Model::unguarded(function (): void {
            Carbon::setTestNow(self::NOW);

            DB::transaction(function (): void {
                $this->purgeDemoData();
                $this->call(DisputeCategorySeeder::class);
                $this->seedCategories();
                $this->seedPackages();
                $this->seedVerificationRequirements();
                $this->seedUsersAndProfiles();
                $this->seedVerificationSubmissions();
                $this->seedCampaignsAndVersions();
                $this->seedMarketingResources();
                $this->seedFeaturedAndExtensions();
                $this->seedDealsEvidenceCommissions();
                $this->seedDisputes();
                $this->seedConversations();
                $this->seedNotifications();
            });

            Carbon::setTestNow();
        });

        $this->printSummary();
    }

    private function purgeDemoData(): void
    {
        $demoUserIds = User::query()
            ->where('email', 'like', '%@'.DevelopmentSeedGuard::demoEmailDomain())
            ->pluck('id');

        $demoCampaignIds = Campaign::query()
            ->whereIn('user_id', $demoUserIds)
            ->pluck('id');

        $demoDealIds = Deal::query()
            ->whereIn('campaign_id', $demoCampaignIds)
            ->orWhereIn('business_user_id', $demoUserIds)
            ->orWhereIn('ambassador_user_id', $demoUserIds)
            ->pluck('id');

        $demoCommissionIds = Commission::query()
            ->whereIn('deal_id', $demoDealIds)
            ->pluck('id');

        $demoDisputeIds = Dispute::query()
            ->whereIn('deal_id', $demoDealIds)
            ->pluck('id');

        $demoConversationIds = Conversation::query()
            ->whereIn('business_user_id', $demoUserIds)
            ->orWhereIn('ambassador_user_id', $demoUserIds)
            ->pluck('id');

        $demoPaymentIds = PlatformPayment::query()
            ->whereIn('user_id', $demoUserIds)
            ->pluck('id');

        Message::query()->whereIn('conversation_id', $demoConversationIds)->delete();
        ConversationParticipant::query()->whereIn('conversation_id', $demoConversationIds)->delete();
        Conversation::query()->whereIn('id', $demoConversationIds)->delete();

        if (Schema::hasTable('dispute_attachments')) {
            DB::table('dispute_attachments')->whereIn('dispute_id', $demoDisputeIds)->delete();
        }
        DisputeEvent::query()->whereIn('dispute_id', $demoDisputeIds)->delete();
        Dispute::query()->whereIn('id', $demoDisputeIds)->delete();

        CommissionEvent::query()->whereIn('commission_id', $demoCommissionIds)->delete();
        Commission::query()->whereIn('id', $demoCommissionIds)->delete();

        PaymentEvidence::query()->whereIn('deal_id', $demoDealIds)->delete();
        DealEvent::query()->whereIn('deal_id', $demoDealIds)->delete();
        Deal::query()->whereIn('id', $demoDealIds)->delete();

        CampaignFeaturedPurchase::query()->whereIn('campaign_id', $demoCampaignIds)->delete();
        CampaignExtension::query()->whereIn('campaign_id', $demoCampaignIds)->delete();
        PlatformPayment::query()->whereIn('id', $demoPaymentIds)->delete();

        CampaignMarketingResource::query()->whereIn('campaign_id', $demoCampaignIds)->delete();
        Campaign::query()->whereIn('id', $demoCampaignIds)->update(['current_campaign_version_id' => null]);
        CampaignVersion::query()->whereIn('campaign_id', $demoCampaignIds)->delete();
        Campaign::query()->whereIn('id', $demoCampaignIds)->delete();

        VerificationSubmission::query()->whereIn('user_id', $demoUserIds)->delete();
        BusinessProfile::query()->whereIn('user_id', $demoUserIds)->delete();
        AmbassadorProfile::query()->whereIn('user_id', $demoUserIds)->delete();

        DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $demoUserIds)
            ->delete();

        User::query()->whereIn('id', $demoUserIds)->delete();

        Category::query()->where('slug', 'like', 'demo-%')->delete();
        CampaignFeaturedPackage::query()->where('name', 'like', 'Demo %')->delete();
        CampaignExtensionPackage::query()->where('name', 'like', 'Demo %')->delete();
        VerificationRequirement::query()->where('name', 'like', 'Demo %')->delete();
    }

    private function seedCategories(): void
    {
        $rows = [
            ['slug' => 'demo-technology', 'name' => 'Technology', 'listing_status' => CategoryListingStatus::Allowed],
            ['slug' => 'demo-logistics', 'name' => 'Logistics', 'listing_status' => CategoryListingStatus::Allowed],
            ['slug' => 'demo-education', 'name' => 'Education', 'listing_status' => CategoryListingStatus::Allowed],
            ['slug' => 'demo-solar-energy', 'name' => 'Solar & Energy', 'listing_status' => CategoryListingStatus::Allowed],
            ['slug' => 'demo-hospitality', 'name' => 'Hospitality', 'listing_status' => CategoryListingStatus::Restricted],
            ['slug' => 'demo-real-estate', 'name' => 'Real Estate', 'listing_status' => CategoryListingStatus::Allowed],
            ['slug' => 'demo-professional', 'name' => 'Professional Services', 'listing_status' => CategoryListingStatus::Allowed],
            ['slug' => 'demo-prohibited', 'name' => 'Prohibited Demo Sector', 'listing_status' => CategoryListingStatus::Prohibited, 'is_active' => false],
        ];

        foreach ($rows as $i => $row) {
            $category = Category::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'description' => $row['name'].' category for development/UAT.',
                    'listing_status' => $row['listing_status'],
                    'is_active' => $row['is_active'] ?? true,
                    'sort_order' => ($i + 1) * 10,
                ],
            );
            $this->categories[$row['slug']] = $category;
        }
    }

    private function seedPackages(): void
    {
        $this->featuredPackages['7d'] = CampaignFeaturedPackage::query()->updateOrCreate(
            ['name' => 'Demo Featured 7 days'],
            [
                'duration_days' => 7,
                'amount_minor' => 250000,
                'currency' => 'NGN',
                'is_active' => true,
                'sort_order' => 10,
            ],
        );

        CampaignFeaturedPackage::query()->updateOrCreate(
            ['name' => 'Demo Featured 14 days'],
            [
                'duration_days' => 14,
                'amount_minor' => 400000,
                'currency' => 'NGN',
                'is_active' => true,
                'sort_order' => 20,
            ],
        );

        CampaignExtensionPackage::query()->updateOrCreate(
            ['name' => 'Demo Extension 30 days'],
            [
                'duration_days' => 30,
                'amount_minor' => 1500000,
                'currency' => 'NGN',
                'is_active' => true,
                'sort_order' => 10,
            ],
        );
    }

    private function seedVerificationRequirements(): void
    {
        VerificationRequirement::query()->updateOrCreate(
            ['name' => 'Demo Business legal name', 'participant_type' => Role::Business->value],
            [
                'description' => 'Provide the registered legal name.',
                'requirement_type' => VerificationRequirementType::Text,
                'is_required' => true,
                'is_active' => true,
                'sort_order' => 10,
            ],
        );

        VerificationRequirement::query()->updateOrCreate(
            ['name' => 'Demo Business registration evidence', 'participant_type' => Role::Business->value],
            [
                'description' => 'Upload fictional CAC evidence for development only.',
                'requirement_type' => VerificationRequirementType::Document,
                'is_required' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
        );

        VerificationRequirement::query()->updateOrCreate(
            ['name' => 'Demo Ambassador legal name', 'participant_type' => Role::Ambassador->value],
            [
                'description' => 'Provide your legal name.',
                'requirement_type' => VerificationRequirementType::Text,
                'is_required' => true,
                'is_active' => true,
                'sort_order' => 10,
            ],
        );

        VerificationRequirement::query()->updateOrCreate(
            ['name' => 'Demo Ambassador identity evidence', 'participant_type' => Role::Ambassador->value],
            [
                'description' => 'Upload fictional identity evidence for development only.',
                'requirement_type' => VerificationRequirementType::Document,
                'is_required' => true,
                'is_active' => true,
                'sort_order' => 20,
            ],
        );
    }

    private function seedUsersAndProfiles(): void
    {
        $this->users['admin.primary'] = $this->user('admin.primary', 'Primary Demo Admin', Role::Admin, AccountStatus::Active);
        $this->users['admin.ops'] = $this->user('admin.ops', 'Ops Demo Admin', Role::Admin, AccountStatus::Active);

        $businesses = [
            'business.solar' => ['Ada Solar Ventures Ltd', 'Ada Solar', 'demo-solar-energy', AccountStatus::Active, 'Lagos'],
            'business.tech' => ['Nimbus Tech Nigeria Ltd', 'Nimbus Tech', 'demo-technology', AccountStatus::Active, 'Abuja'],
            'business.logistics' => ['SwiftHaul Logistics Ltd', 'SwiftHaul', 'demo-logistics', AccountStatus::Active, 'Port Harcourt'],
            'business.edu' => ['BrightPath Education Ltd', 'BrightPath', 'demo-education', AccountStatus::Restricted, 'Ibadan'],
            'business.hospitality' => ['Coral Inns Limited', 'Coral Inns', 'demo-hospitality', AccountStatus::Suspended, 'Calabar'],
        ];

        foreach ($businesses as $key => [$legal, $trading, $categorySlug, $status, $city]) {
            $user = $this->user($key, $legal, Role::Business, $status);
            BusinessProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'legal_name' => $legal,
                    'trading_name' => $trading,
                    'description' => $trading.' is a fictional development business for UAT.',
                    'category' => $this->categories[$categorySlug]->name,
                    'address' => '12 Demo Street, '.$city,
                    'operating_location' => $city.', Nigeria',
                    'contact_email' => $key.'@'.DevelopmentSeedGuard::demoEmailDomain(),
                    'contact_phone' => '0803000'.substr((string) crc32($key), 0, 4),
                    'website' => 'https://example.test/'.$key,
                    'social_links' => ['instagram' => 'https://instagram.com/demo_'.$key],
                ],
            );
        }

        $ambassadors = [
            'ambassador.ada' => ['Ada Okonkwo', 'Lagos', AccountStatus::Active],
            'ambassador.chidi' => ['Chidi Eze', 'Enugu', AccountStatus::Active],
            'ambassador.funke' => ['Funke Adeyemi', 'Ibadan', AccountStatus::Active],
            'ambassador.restricted' => ['Restricted Demo Ambassador', 'Kano', AccountStatus::Restricted],
            'ambassador.banned' => ['Banned Demo Ambassador', 'Jos', AccountStatus::Banned],
        ];

        foreach ($ambassadors as $key => [$name, $city, $status]) {
            $user = $this->user($key, $name, Role::Ambassador, $status);
            AmbassadorProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'display_name' => $name,
                    'profile_description' => $name.' promotes campaigns on MarcatursHub demo data.',
                    'location' => $city.', Nigeria',
                    'skills' => ['social-media', 'field-sales', 'content'],
                    'marketing_interests' => ['retail', 'education', 'energy'],
                    'experience' => '3+ years fictional ambassador experience for UAT.',
                ],
            );
        }
    }

    private function user(string $key, string $name, Role $role, AccountStatus $status): User
    {
        $email = $key.'@'.DevelopmentSeedGuard::demoEmailDomain();

        $user = User::query()->where('email', $email)->first() ?? new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = self::DEMO_PASSWORD;
        $user->role = $role;
        $user->status = $status;
        $user->email_verified_at = now()->subDays(10);
        $user->save();

        $this->users[$key] = $user->fresh();

        return $this->users[$key];
    }

    private function seedVerificationSubmissions(): void
    {
        $bizReqs = VerificationRequirement::query()
            ->where('participant_type', Role::Business->value)
            ->where('name', 'like', 'Demo %')
            ->orderBy('sort_order')
            ->get();

        $ambReqs = VerificationRequirement::query()
            ->where('participant_type', Role::Ambassador->value)
            ->where('name', 'like', 'Demo %')
            ->orderBy('sort_order')
            ->get();

        // Solar business: fully approved
        foreach ($bizReqs as $req) {
            $this->submission($this->users['business.solar'], $req, VerificationSubmissionStatus::Approved);
        }

        // Tech: under review
        $this->submission($this->users['business.tech'], $bizReqs[0], VerificationSubmissionStatus::UnderReview);
        $this->submission($this->users['business.tech'], $bizReqs[1], VerificationSubmissionStatus::Pending);

        // Logistics: rejected + more info
        $this->submission($this->users['business.logistics'], $bizReqs[0], VerificationSubmissionStatus::Rejected, 'Fictional document unclear.');
        $this->submission($this->users['business.logistics'], $bizReqs[1], VerificationSubmissionStatus::MoreInformationRequired, 'Please re-upload clearer scan.');

        // Edu restricted: no submissions (not started)

        foreach ($ambReqs as $req) {
            $this->submission($this->users['ambassador.ada'], $req, VerificationSubmissionStatus::Approved);
            $this->submission($this->users['ambassador.chidi'], $req, VerificationSubmissionStatus::Pending);
        }
        $this->submission($this->users['ambassador.funke'], $ambReqs[0], VerificationSubmissionStatus::UnderReview);
    }

    private function submission(
        User $user,
        VerificationRequirement $requirement,
        VerificationSubmissionStatus $status,
        ?string $reviewerNotes = null,
    ): void {
        VerificationSubmission::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'verification_requirement_id' => $requirement->id,
            ],
            [
                'status' => $status,
                'submitted_at' => now()->subDays(3),
                'text_value' => 'Demo submission for '.$requirement->name,
                'current_version' => 1,
                'review_reason' => $reviewerNotes,
                'reviewer_notes' => $reviewerNotes,
                'reviewed_at' => in_array($status, [
                    VerificationSubmissionStatus::Approved,
                    VerificationSubmissionStatus::Rejected,
                    VerificationSubmissionStatus::MoreInformationRequired,
                    VerificationSubmissionStatus::UnderReview,
                ], true) ? now()->subDay() : null,
                'reviewed_by' => in_array($status, [
                    VerificationSubmissionStatus::Approved,
                    VerificationSubmissionStatus::Rejected,
                    VerificationSubmissionStatus::MoreInformationRequired,
                ], true) ? $this->users['admin.primary']->id : null,
            ],
        );
    }

    private function seedCampaignsAndVersions(): void
    {
        $now = now();

        $this->makeCampaign('solar_active', $this->users['business.solar'], $this->categories['demo-solar-energy'], CampaignStatus::Active, [
            'title' => 'Demo Solar Street Light Kits',
            'listing_starts_at' => $now->copy()->subDays(10),
            'listing_expires_at' => $now->copy()->addDays(20),
            'activated_at' => $now->copy()->subDays(10),
            'approved_at' => $now->copy()->subDays(11),
            'submitted_at' => $now->copy()->subDays(12),
            'is_featured' => true,
        ], 'Solar street light installation kit', '12.00');

        $this->makeCampaign('tech_expiring', $this->users['business.tech'], $this->categories['demo-technology'], CampaignStatus::Expiring, [
            'title' => 'Demo Classroom Internet Bundle',
            'listing_starts_at' => $now->copy()->subDays(28),
            'listing_expires_at' => $now->copy()->addDays(2),
            'activated_at' => $now->copy()->subDays(28),
            'approved_at' => $now->copy()->subDays(29),
            'submitted_at' => $now->copy()->subDays(30),
            'is_featured' => true,
        ], 'Classroom internet package', '10.00');

        $this->makeCampaign('logistics_expired', $this->users['business.logistics'], $this->categories['demo-logistics'], CampaignStatus::Expired, [
            'title' => 'Demo Last-Mile Delivery Promo',
            'listing_starts_at' => $now->copy()->subDays(60),
            'listing_expires_at' => $now->copy()->subDays(5),
            'activated_at' => $now->copy()->subDays(60),
            'expired_at' => $now->copy()->subDays(5),
            'approved_at' => $now->copy()->subDays(61),
            'submitted_at' => $now->copy()->subDays(62),
        ], 'Same-day parcel delivery', '8.00');

        $this->makeCampaign('solar_draft', $this->users['business.solar'], $this->categories['demo-solar-energy'], CampaignStatus::Draft, [
            'title' => 'Demo Draft Inverter Campaign',
        ], null, null, false);

        $this->makeCampaign('tech_submitted', $this->users['business.tech'], $this->categories['demo-technology'], CampaignStatus::Submitted, [
            'title' => 'Demo Submitted POS Terminals',
            'submitted_at' => $now->copy()->subDay(),
        ], 'POS terminal kit', '15.00');

        $this->makeCampaign('logistics_approved', $this->users['business.logistics'], $this->categories['demo-logistics'], CampaignStatus::Approved, [
            'title' => 'Demo Approved Fleet Wrap Ads',
            'submitted_at' => $now->copy()->subDays(4),
            'approved_at' => $now->copy()->subDays(2),
        ], 'Fleet wrap advertising', '9.00');

        $this->makeCampaign('solar_deactivated', $this->users['business.solar'], $this->categories['demo-solar-energy'], CampaignStatus::Deactivated, [
            'title' => 'Demo Deactivated Panel Offer',
            'listing_starts_at' => $now->copy()->subDays(40),
            'listing_expires_at' => $now->copy()->addDays(5),
            'activated_at' => $now->copy()->subDays(40),
            'deactivated_at' => $now->copy()->subDays(3),
            'approved_at' => $now->copy()->subDays(41),
            'submitted_at' => $now->copy()->subDays(42),
        ], 'Rooftop solar panel set', '11.00');

        $this->makeCampaign('tech_suspended', $this->users['business.tech'], $this->categories['demo-technology'], CampaignStatus::Suspended, [
            'title' => 'Demo Suspended Router Promo',
            'listing_starts_at' => $now->copy()->subDays(15),
            'listing_expires_at' => $now->copy()->addDays(15),
            'activated_at' => $now->copy()->subDays(15),
            'suspended_at' => $now->copy()->subDays(1),
            'approved_at' => $now->copy()->subDays(16),
            'submitted_at' => $now->copy()->subDays(17),
        ], 'Enterprise router bundle', '7.00');

        $this->makeCampaign('logistics_closed', $this->users['business.logistics'], $this->categories['demo-logistics'], CampaignStatus::Closed, [
            'title' => 'Demo Closed Warehouse Clearance',
            'listing_starts_at' => $now->copy()->subDays(90),
            'listing_expires_at' => $now->copy()->subDays(20),
            'activated_at' => $now->copy()->subDays(90),
            'closed_at' => $now->copy()->subDays(10),
            'approved_at' => $now->copy()->subDays(91),
            'submitted_at' => $now->copy()->subDays(92),
        ], 'Warehouse clearance service', '6.00');
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeCampaign(
        string $key,
        User $owner,
        Category $category,
        CampaignStatus $status,
        array $attrs,
        ?string $productName,
        ?string $commissionRate,
        bool $withPublishedVersion = true,
    ): void {
        $campaign = new Campaign;
        $campaign->user_id = $owner->id;
        $campaign->category_id = $category->id;
        $campaign->title = (string) $attrs['title'];
        $campaign->status = $status;
        $campaign->is_featured = (bool) ($attrs['is_featured'] ?? false);
        foreach ([
            'listing_starts_at', 'listing_expires_at', 'submitted_at', 'approved_at',
            'activated_at', 'deactivated_at', 'expired_at', 'closed_at', 'suspended_at',
        ] as $field) {
            if (array_key_exists($field, $attrs)) {
                $campaign->{$field} = $attrs[$field];
            }
        }
        $campaign->save();

        if ($withPublishedVersion && $productName !== null && $commissionRate !== null) {
            $version = new CampaignVersion;
            $version->campaign_id = $campaign->id;
            $version->version_number = 1;
            $version->status = CampaignVersionStatus::Published;
            $version->published_at = now()->subDays(12);
            $version->product_name = $productName;
            $version->product_description = $productName.' — fictional development offer.';
            $version->pricing_method = PricingMethod::Fixed;
            $version->price_amount = '250000.00';
            $version->price_currency = 'NGN';
            $version->commission_type = CommissionType::Percentage;
            $version->commission_rate = $commissionRate;
            $version->commission_trigger = CommissionTrigger::PaymentConfirmation;
            $version->commission_payment_deadline_days = 7;
            $version->refund_cancellation_rules = 'Customer refunds are handled by the business off-platform.';
            $version->service_area = 'Lagos and surrounding areas';
            $version->payment_destination_name = $owner->name;
            $version->payment_provider = 'Demo Bank PLC';
            $version->payment_account_identifier = '0123456789';
            $version->payment_instructions = 'Pay the business directly. MarcatursHub does not receive this payment.';
            $version->save();

            if (in_array($status, [
                CampaignStatus::Submitted,
                CampaignStatus::Approved,
                CampaignStatus::Active,
                CampaignStatus::Expiring,
                CampaignStatus::Expired,
                CampaignStatus::Deactivated,
                CampaignStatus::Suspended,
                CampaignStatus::Closed,
            ], true)) {
                $campaign->current_campaign_version_id = $version->id;
                $campaign->save();
            }

            // Second historical draft version on active solar campaign
            if ($key === 'solar_active') {
                $draft = $version->replicate(['status', 'published_at', 'version_number']);
                $draft->version_number = 2;
                $draft->status = CampaignVersionStatus::Draft;
                $draft->published_at = null;
                $draft->product_name = $productName.' (draft v2)';
                $draft->save();
            }

            $this->versions[$key] = $version->fresh();
        }

        $this->campaigns[$key] = $campaign->fresh();
    }

    private function seedMarketingResources(): void
    {
        $campaign = $this->campaigns['solar_active'];
        $uploader = $this->users['business.solar'];

        foreach ([
            [CampaignMarketingResourceType::Image, 'Hero product image', 'image/jpeg', 'hero.jpg'],
            [CampaignMarketingResourceType::Flyer, 'Promo flyer PDF', 'application/pdf', 'flyer.pdf'],
            [CampaignMarketingResourceType::Brochure, 'Product brochure', 'application/pdf', 'brochure.pdf'],
        ] as $i => [$type, $title, $mime, $filename]) {
            $resource = new CampaignMarketingResource;
            $resource->campaign_id = $campaign->id;
            $resource->uploaded_by = $uploader->id;
            $resource->type = $type;
            $resource->title = $title;
            $resource->description = 'Development fixture metadata only.';
            $resource->disk = 'campaign_media';
            $resource->path = 'campaigns/'.$campaign->id.'/resources/demo-'.($i + 1).'-'.$filename;
            $resource->original_filename = $filename;
            $resource->mime_type = $mime;
            $resource->size_bytes = 1024 * ($i + 1);
            $resource->sort_order = ($i + 1) * 10;
            $resource->save();
        }
    }

    private function seedFeaturedAndExtensions(): void
    {
        $pkg = $this->featuredPackages['7d'];
        $extPkg = CampaignExtensionPackage::query()->where('name', 'Demo Extension 30 days')->firstOrFail();

        $this->paidFeatured($this->campaigns['solar_active'], $this->users['business.solar'], $pkg, now()->subDays(2), now()->addDays(5), 'mh_feat_demo_solar_1');
        $this->paidFeatured($this->campaigns['solar_active'], $this->users['business.solar'], $pkg, now()->subDay(), now()->addDays(12), 'mh_feat_demo_solar_2'); // stacked
        $this->paidFeatured($this->campaigns['tech_expiring'], $this->users['business.tech'], $pkg, now()->subDays(3), now()->addDays(4), 'mh_feat_demo_tech_1');

        $payment = new PlatformPayment;
        $payment->user_id = $this->users['business.logistics']->id;
        $payment->campaign_id = $this->campaigns['logistics_expired']->id;
        $payment->campaign_extension_package_id = $extPkg->id;
        $payment->purpose = PlatformPaymentPurpose::CampaignExtension;
        $payment->provider = 'paystack';
        $payment->reference = 'mh_ext_demo_logistics_1';
        $payment->provider_reference = 'demo_ext_provider_1';
        $payment->amount_minor = $extPkg->amount_minor;
        $payment->currency = 'NGN';
        $payment->duration_days = $extPkg->duration_days;
        $payment->status = PlatformPaymentStatus::Paid;
        $payment->paid_at = now()->subDays(40);
        $payment->save();

        $extension = new CampaignExtension;
        $extension->campaign_id = $this->campaigns['logistics_expired']->id;
        $extension->user_id = $this->users['business.logistics']->id;
        $extension->campaign_extension_package_id = $extPkg->id;
        $extension->platform_payment_id = $payment->id;
        $extension->duration_days = 30;
        $extension->amount_minor = $extPkg->amount_minor;
        $extension->currency = 'NGN';
        $extension->previous_listing_expires_at = now()->subDays(45);
        $extension->resulting_listing_expires_at = now()->subDays(5);
        $extension->previous_status = CampaignStatus::Active;
        $extension->resulting_status = CampaignStatus::Active;
        $extension->applied_at = now()->subDays(40);
        $extension->save();
    }

    private function paidFeatured(
        Campaign $campaign,
        User $user,
        CampaignFeaturedPackage $package,
        Carbon $activatedAt,
        Carbon $expiresAt,
        string $reference,
    ): void {
        $payment = new PlatformPayment;
        $payment->user_id = $user->id;
        $payment->campaign_id = $campaign->id;
        $payment->campaign_featured_package_id = $package->id;
        $payment->purpose = PlatformPaymentPurpose::CampaignFeatured;
        $payment->provider = 'paystack';
        $payment->reference = $reference;
        $payment->provider_reference = 'provider_'.$reference;
        $payment->amount_minor = $package->amount_minor;
        $payment->currency = 'NGN';
        $payment->duration_days = $package->duration_days;
        $payment->status = PlatformPaymentStatus::Paid;
        $payment->paid_at = $activatedAt;
        $payment->save();

        $purchase = new CampaignFeaturedPurchase;
        $purchase->campaign_id = $campaign->id;
        $purchase->user_id = $user->id;
        $purchase->campaign_featured_package_id = $package->id;
        $purchase->platform_payment_id = $payment->id;
        $purchase->package_name = $package->name;
        $purchase->duration_days = $package->duration_days;
        $purchase->amount_minor = $package->amount_minor;
        $purchase->currency = 'NGN';
        $purchase->activated_at = $activatedAt;
        $purchase->expires_at = $expiresAt;
        $purchase->save();
    }

    private function seedDealsEvidenceCommissions(): void
    {
        $solar = $this->campaigns['solar_active'];
        $solarVersion = $this->versions['solar_active'];
        $tech = $this->campaigns['tech_expiring'];
        $techVersion = $this->versions['tech_expiring'];

        $this->deals['pending'] = $this->deal(
            $this->users['business.solar'],
            $this->users['ambassador.ada'],
            $solar,
            $solarVersion,
            DealStatus::PaymentPending,
        );

        $this->deals['sealed'] = $this->deal(
            $this->users['business.solar'],
            $this->users['ambassador.chidi'],
            $solar,
            $solarVersion,
            DealStatus::Sealed,
            confirmed: true,
        );

        $this->deals['completed'] = $this->deal(
            $this->users['business.tech'],
            $this->users['ambassador.ada'],
            $tech,
            $techVersion,
            DealStatus::Completed,
            confirmed: true,
        );

        $this->deals['cancelled'] = $this->deal(
            $this->users['business.tech'],
            $this->users['ambassador.funke'],
            $tech,
            $techVersion,
            DealStatus::Cancelled,
        );

        $this->deals['overdue'] = $this->deal(
            $this->users['business.solar'],
            $this->users['ambassador.funke'],
            $solar,
            $solarVersion,
            DealStatus::Sealed,
            confirmed: true,
            confirmedAt: now()->subDays(20),
        );

        // Evidence on pending deal
        $evidence = new PaymentEvidence;
        $evidence->deal_id = $this->deals['pending']->id;
        $evidence->ambassador_user_id = $this->users['ambassador.ada']->id;
        $evidence->kind = PaymentEvidenceKind::TransactionReference;
        $evidence->status = PaymentEvidenceStatus::Submitted;
        $evidence->reference_number = 'DEMO-TXN-10001';
        $evidence->amount = '250000.00';
        $evidence->currency = 'NGN';
        $evidence->paid_on = now()->subDay()->toDateString();
        $evidence->submitted_at = now()->subDay();
        $evidence->save();

        $rejected = new PaymentEvidence;
        $rejected->deal_id = $this->deals['pending']->id;
        $rejected->ambassador_user_id = $this->users['ambassador.ada']->id;
        $rejected->kind = PaymentEvidenceKind::Receipt;
        $rejected->status = PaymentEvidenceStatus::Rejected;
        $rejected->disk = 'sensitive';
        $rejected->path = 'deals/'.$this->deals['pending']->id.'/evidence/demo-rejected.pdf';
        $rejected->original_filename = 'receipt.pdf';
        $rejected->mime_type = 'application/pdf';
        $rejected->size_bytes = 2048;
        $rejected->submitted_at = now()->subDays(2);
        $rejected->save();

        $this->commissions['due'] = $this->commission($this->deals['sealed'], CommissionStatus::Due, now()->subDays(2), now()->addDays(5));
        $this->commissions['overdue'] = $this->commission($this->deals['overdue'], CommissionStatus::Due, now()->subDays(20), now()->subDays(13));
        $this->commissions['paid'] = $this->commission($this->deals['completed'], CommissionStatus::Paid, now()->subDays(15), now()->subDays(8), paidAt: now()->subDays(3));
        // Separate sealed deal path already used completed for paid; create received on a duplicate commission isn't allowed (unique deal_id).
        // Mark paid commission then also create received via updating paid one for "received" scenario — use completed deal as received instead.
        $this->commissions['paid']->status = CommissionStatus::Received;
        $this->commissions['paid']->received_at = now()->subDay();
        $this->commissions['paid']->payment_reference = 'DEMO-COMM-RECV-1';
        $this->commissions['paid']->save();
        $this->commissions['received'] = $this->commissions['paid'];

        // Add a distinct paid-not-received commission: need another sealed/completed deal
        $extra = $this->deal(
            $this->users['business.logistics'],
            $this->users['ambassador.chidi'],
            $this->campaigns['logistics_expired'],
            $this->versions['logistics_expired'],
            DealStatus::Sealed,
            confirmed: true,
            confirmedAt: now()->subDays(10),
        );
        $this->deals['paid_only'] = $extra;
        $this->commissions['paid_only'] = $this->commission($extra, CommissionStatus::Paid, now()->subDays(10), now()->subDays(3), paidAt: now()->subDays(2));

        $this->commissionEvent($this->commissions['overdue'], CommissionEventType::Overdue, CommissionStatus::Due, CommissionStatus::Due, null);
        $this->commissionEvent($this->commissions['paid_only'], CommissionEventType::Paid, CommissionStatus::Due, CommissionStatus::Paid, $this->users['business.logistics']);
        $this->commissionEvent($this->commissions['received'], CommissionEventType::Paid, CommissionStatus::Due, CommissionStatus::Paid, $this->users['business.tech']);
        $this->commissionEvent($this->commissions['received'], CommissionEventType::Received, CommissionStatus::Paid, CommissionStatus::Received, $this->users['ambassador.ada']);
    }

    private function deal(
        User $business,
        User $ambassador,
        Campaign $campaign,
        CampaignVersion $version,
        DealStatus $status,
        bool $confirmed = false,
        ?Carbon $confirmedAt = null,
    ): Deal {
        $deal = new Deal;
        $deal->business_user_id = $business->id;
        $deal->ambassador_user_id = $ambassador->id;
        $deal->campaign_id = $campaign->id;
        $deal->campaign_version_id = $version->id;
        $deal->status = $status;
        $deal->product_name = $version->product_name;
        $deal->pricing_method = $version->pricing_method;
        $deal->price_amount = $version->price_amount;
        $deal->price_currency = $version->price_currency;
        $deal->commission_type = $version->commission_type;
        $deal->commission_rate = $version->commission_rate;
        $deal->commission_trigger = $version->commission_trigger;
        $deal->commission_payment_deadline_days = $version->commission_payment_deadline_days;
        $deal->expected_transaction_amount = $version->price_amount;
        if ($confirmed) {
            $deal->confirmed_payment_amount = $version->price_amount;
            $deal->confirmed_at = $confirmedAt ?? now()->subDays(5);
        }
        if ($status === DealStatus::Cancelled) {
            $deal->cancelled_at = now()->subDays(2);
        }
        $deal->save();

        $event = new DealEvent;
        $event->deal_id = $deal->id;
        $event->actor_user_id = $ambassador->id;
        $event->type = DealEventType::Created;
        $event->previous_status = null;
        $event->new_status = DealStatus::PaymentPending;
        $event->save();

        if ($status === DealStatus::Cancelled) {
            $cancel = new DealEvent;
            $cancel->deal_id = $deal->id;
            $cancel->actor_user_id = $business->id;
            $cancel->type = DealEventType::Cancelled;
            $cancel->previous_status = DealStatus::PaymentPending;
            $cancel->new_status = DealStatus::Cancelled;
            $cancel->metadata = ['reason' => 'Demo cancellation for UAT.'];
            $cancel->save();
        }

        if ($confirmed) {
            $seal = new DealEvent;
            $seal->deal_id = $deal->id;
            $seal->actor_user_id = $business->id;
            $seal->type = DealEventType::DealSealed;
            $seal->previous_status = DealStatus::PaymentPending;
            $seal->new_status = DealStatus::Sealed;
            $seal->save();
        }

        if ($status === DealStatus::Completed) {
            $done = new DealEvent;
            $done->deal_id = $deal->id;
            $done->actor_user_id = $ambassador->id;
            $done->type = DealEventType::Completed;
            $done->previous_status = DealStatus::Sealed;
            $done->new_status = DealStatus::Completed;
            $done->save();
        }

        return $deal->fresh();
    }

    private function commission(
        Deal $deal,
        CommissionStatus $status,
        Carbon $becameDueAt,
        Carbon $dueAt,
        ?Carbon $paidAt = null,
    ): Commission {
        $commission = new Commission;
        $commission->deal_id = $deal->id;
        $commission->business_user_id = $deal->business_user_id;
        $commission->ambassador_user_id = $deal->ambassador_user_id;
        $commission->campaign_version_id = $deal->campaign_version_id;
        $commission->status = $status;
        $commission->commission_type = $deal->commission_type;
        $commission->commission_rate = $deal->commission_rate;
        $commission->amount = '25000.00';
        $commission->currency = 'NGN';
        $commission->became_due_at = $becameDueAt;
        $commission->due_at = $dueAt;
        $commission->paid_at = $paidAt;
        $commission->payment_reference = $paidAt ? 'DEMO-COMM-PAY-'.$deal->id : null;
        $commission->save();

        return $commission->fresh();
    }

    private function commissionEvent(
        Commission $commission,
        CommissionEventType $type,
        CommissionStatus $previous,
        CommissionStatus $next,
        ?User $actor,
    ): void {
        $event = new CommissionEvent;
        $event->commission_id = $commission->id;
        $event->actor_user_id = $actor?->id;
        $event->type = $type;
        $event->previous_status = $previous;
        $event->new_status = $next;
        $event->save();
    }

    private function seedDisputes(): void
    {
        $category = DisputeCategory::query()->where('code', 'unpaid_commission')->firstOrFail();
        $amountCategory = DisputeCategory::query()->where('code', 'commission_amount_disputed')->firstOrFail();

        $statuses = [
            ['key' => 'submitted', 'status' => DisputeStatus::Submitted, 'deal' => 'sealed', 'events' => [DisputeEventType::Created]],
            ['key' => 'under_review', 'status' => DisputeStatus::UnderReview, 'deal' => 'overdue', 'events' => [DisputeEventType::Created, DisputeEventType::ReviewStarted]],
            ['key' => 'evidence_requested', 'status' => DisputeStatus::EvidenceRequested, 'deal' => 'paid_only', 'events' => [DisputeEventType::Created, DisputeEventType::ReviewStarted, DisputeEventType::EvidenceRequested]],
            ['key' => 'decision_pending', 'status' => DisputeStatus::DecisionPending, 'deal' => 'completed', 'events' => [DisputeEventType::Created, DisputeEventType::ReviewStarted, DisputeEventType::DecisionPending]],
            ['key' => 'resolved', 'status' => DisputeStatus::Resolved, 'deal' => 'sealed', 'events' => [DisputeEventType::Created, DisputeEventType::ReviewStarted, DisputeEventType::Resolved]],
            ['key' => 'closed', 'status' => DisputeStatus::Closed, 'deal' => 'completed', 'events' => [DisputeEventType::Created, DisputeEventType::ReviewStarted, DisputeEventType::Resolved, DisputeEventType::Closed]],
        ];

        // sealed used twice — OK multiple disputes per deal
        foreach ($statuses as $i => $row) {
            $deal = $this->deals[$row['deal']];
            $dispute = new Dispute;
            $dispute->reference = 'MH-D-DEMO'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);
            $dispute->deal_id = $deal->id;
            $dispute->commission_id = Commission::query()->where('deal_id', $deal->id)->value('id');
            $dispute->category_id = $i % 2 === 0 ? $category->id : $amountCategory->id;
            $dispute->reporter_user_id = $deal->ambassador_user_id;
            $dispute->accused_user_id = $deal->business_user_id;
            $dispute->description = 'Demo dispute scenario: '.$row['key'];
            $dispute->status = $row['status'];
            if ($row['status'] === DisputeStatus::Resolved || $row['status'] === DisputeStatus::Closed) {
                $dispute->decision_notes = 'Demo resolution notes for UAT.';
                $dispute->action_notes = 'No financial mutation; operational outcome only.';
                $dispute->resolved_at = now()->subDays(2);
                $dispute->resolved_by_admin_user_id = $this->users['admin.primary']->id;
            }
            if ($row['status'] === DisputeStatus::Closed) {
                $dispute->closed_at = now()->subDay();
                $dispute->closed_by_admin_user_id = $this->users['admin.ops']->id;
            }
            $dispute->save();

            $prev = null;
            foreach ($row['events'] as $eventType) {
                $new = match ($eventType) {
                    DisputeEventType::Created => DisputeStatus::Submitted,
                    DisputeEventType::ReviewStarted => DisputeStatus::UnderReview,
                    DisputeEventType::EvidenceRequested => DisputeStatus::EvidenceRequested,
                    DisputeEventType::DecisionPending => DisputeStatus::DecisionPending,
                    DisputeEventType::Resolved => DisputeStatus::Resolved,
                    DisputeEventType::Closed => DisputeStatus::Closed,
                    default => $row['status'],
                };
                $event = new DisputeEvent;
                $event->dispute_id = $dispute->id;
                $event->actor_user_id = $eventType === DisputeEventType::Created
                    ? $deal->ambassador_user_id
                    : $this->users['admin.primary']->id;
                $event->type = $eventType;
                $event->previous_status = $prev;
                $event->new_status = $new;
                $event->save();
                $prev = $new;
            }
        }
    }

    private function seedConversations(): void
    {
        $conversation = new Conversation;
        $conversation->business_user_id = $this->users['business.solar']->id;
        $conversation->ambassador_user_id = $this->users['ambassador.ada']->id;
        $conversation->save();

        foreach ([$this->users['business.solar']->id, $this->users['ambassador.ada']->id] as $userId) {
            $participant = new ConversationParticipant;
            $participant->conversation_id = $conversation->id;
            $participant->user_id = $userId;
            $participant->save();
        }

        $messages = [
            [$this->users['ambassador.ada'], 'Hi — interested in the solar campaign terms.', now()->subDays(2), now()->subDays(2)],
            [$this->users['business.solar'], 'Welcome. Commission is 12% after payment confirmation.', now()->subDays(2)->addHour(), now()->subDay()],
            [$this->users['ambassador.ada'], 'Thanks. I will share the customer payment evidence soon.', now()->subDay(), null],
        ];

        foreach ($messages as [$sender, $content, $created, $readAt]) {
            $message = new Message;
            $message->conversation_id = $conversation->id;
            $message->sender_id = $sender->id;
            $message->type = MessageType::Text;
            $message->content = $content;
            $message->read_at = $readAt;
            $message->created_at = $created;
            $message->updated_at = $created;
            $message->save();
        }

        $second = new Conversation;
        $second->business_user_id = $this->users['business.tech']->id;
        $second->ambassador_user_id = $this->users['ambassador.chidi']->id;
        $second->save();
        foreach ([$this->users['business.tech']->id, $this->users['ambassador.chidi']->id] as $userId) {
            $participant = new ConversationParticipant;
            $participant->conversation_id = $second->id;
            $participant->user_id = $userId;
            $participant->save();
        }
        $message = new Message;
        $message->conversation_id = $second->id;
        $message->sender_id = $this->users['ambassador.chidi']->id;
        $message->type = MessageType::Text;
        $message->content = 'Quick question on the classroom internet package.';
        $message->save();
    }

    private function seedNotifications(): void
    {
        $now = now();

        $rows = [
            [$this->users['business.solar'], NotificationType::CommissionDue, false, ['commission_id' => $this->commissions['due']->id]],
            [$this->users['business.solar'], NotificationType::CommissionPreDeadline, true, ['commission_id' => $this->commissions['due']->id]],
            [$this->users['business.solar'], NotificationType::CommissionDeadline, false, ['commission_id' => $this->commissions['due']->id]],
            [$this->users['business.solar'], NotificationType::CommissionOverdue, false, ['commission_id' => $this->commissions['overdue']->id]],
            [$this->users['business.solar'], NotificationType::CommissionOverdueFollowUp, true, ['commission_id' => $this->commissions['overdue']->id]],
            [$this->users['business.tech'], NotificationType::CommissionPaid, true, ['commission_id' => $this->commissions['received']->id]],
            [$this->users['ambassador.ada'], NotificationType::CommissionReceived, false, ['commission_id' => $this->commissions['received']->id]],
            [$this->users['business.solar'], NotificationType::CampaignFeaturedPurchased, false, ['campaign_id' => $this->campaigns['solar_active']->id]],
            [$this->users['business.tech'], NotificationType::DealCancelled, true, ['deal_id' => $this->deals['cancelled']->id]],
            [$this->users['ambassador.chidi'], NotificationType::DisputeOpened, false, ['deal_id' => $this->deals['sealed']->id]],
            [$this->users['business.solar'], NotificationType::DisputeResolved, true, ['deal_id' => $this->deals['sealed']->id]],
        ];

        foreach ($rows as $i => [$user, $type, $read, $extra]) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => match ($type) {
                    NotificationType::CampaignFeaturedPurchased => CampaignFeaturedPurchasedNotification::class,
                    NotificationType::DealCancelled => DealCancelledNotification::class,
                    NotificationType::DisputeOpened, NotificationType::DisputeResolved => DisputeNotification::class,
                    default => CommissionNotification::class,
                },
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode(array_merge([
                    'notification_type' => $type->value,
                    'title' => 'Demo '.$type->value,
                    'body' => 'Development/UAT notification payload (no secrets).',
                ], $extra), JSON_THROW_ON_ERROR),
                'read_at' => $read ? $now->copy()->subHours(2)->toDateTimeString() : null,
                'idempotency_key' => 'demo:notification:'.$type->value.':'.$i,
                'created_at' => $now->copy()->subHours($i + 1)->toDateTimeString(),
                'updated_at' => $now->copy()->subHours($i + 1)->toDateTimeString(),
            ]);
        }
    }

    private function printSummary(): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->info('Development/UAT scenario seeded.');
        $this->command->line('Demo password for all *@'.DevelopmentSeedGuard::demoEmailDomain().' users: '.self::DEMO_PASSWORD);
        $this->command->line('Primary admin: admin.primary@'.DevelopmentSeedGuard::demoEmailDomain());
        $this->command->line('See docs/development-seed.md for the full scenario matrix.');
    }
}
