<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64);
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('code', 'dispute_categories_code_unq');
            $table->index(['is_active', 'sort_order', 'id'], 'dispute_categories_active_sort_idx');
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 32);
            $table->foreignId('deal_id')->constrained('deals')->restrictOnDelete();
            $table->foreignId('commission_id')->nullable()->constrained('commissions')->nullOnDelete();
            $table->foreignId('category_id')->constrained('dispute_categories')->restrictOnDelete();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('accused_user_id')->constrained('users')->restrictOnDelete();
            $table->text('description');
            $table->string('status', 32);
            $table->text('decision_notes')->nullable();
            $table->text('action_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('resolved_by_admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('reference', 'disputes_reference_unq');
            $table->index(['deal_id', 'id'], 'disputes_deal_id_idx');
            $table->index(['status', 'id'], 'disputes_status_idx');
            $table->index(['reporter_user_id', 'id'], 'disputes_reporter_idx');
            $table->index(['accused_user_id', 'id'], 'disputes_accused_idx');
        });

        Schema::create('dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 64);
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['dispute_id', 'id'], 'dispute_events_dispute_id_idx');
            $table->index('type', 'dispute_events_type_idx');
        });

        Schema::create('dispute_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained('disputes')->cascadeOnDelete();
            $table->foreignId('uploader_user_id')->constrained('users')->restrictOnDelete();
            $table->string('disk', 64);
            $table->string('path', 500);
            $table->string('original_filename', 180)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['dispute_id', 'id'], 'dispute_attachments_dispute_id_idx');
        });

        $now = now();
        $categories = [
            ['code' => 'unpaid_commission', 'name' => 'Unpaid commission', 'sort_order' => 10],
            ['code' => 'commission_amount_disputed', 'name' => 'Commission amount disputed', 'sort_order' => 20],
            ['code' => 'campaign_terms_changed', 'name' => 'Campaign terms changed', 'sort_order' => 30],
            ['code' => 'commission_withheld', 'name' => 'Commission withheld', 'sort_order' => 40],
            ['code' => 'unauthorized_claims', 'name' => 'Unauthorized claims', 'sort_order' => 50],
            ['code' => 'fraudulent_evidence', 'name' => 'Fraudulent evidence', 'sort_order' => 60],
            ['code' => 'misrepresentation', 'name' => 'Misrepresentation', 'sort_order' => 70],
            ['code' => 'customer_complaint', 'name' => 'Customer complaint', 'sort_order' => 80],
            ['code' => 'campaign_abuse', 'name' => 'Campaign abuse', 'sort_order' => 90],
            ['code' => 'other', 'name' => 'Other', 'sort_order' => 100],
        ];

        foreach ($categories as $category) {
            DB::table('dispute_categories')->insert([
                'code' => $category['code'],
                'name' => $category['name'],
                'description' => null,
                'is_active' => true,
                'sort_order' => $category['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_attachments');
        Schema::dropIfExists('dispute_events');
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('dispute_categories');
    }
};
