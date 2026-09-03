<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')
                ->constrained('campaigns', 'id', 'cv_campaign_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 32);

            $table->string('product_name');
            $table->text('product_description')->nullable();
            $table->string('pricing_method', 32)->nullable();
            $table->decimal('price_amount', 15, 2)->nullable();
            $table->char('price_currency', 3)->default('NGN');
            $table->string('service_area')->nullable();

            $table->string('commission_type', 32)->nullable();
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('commission_amount', 15, 2)->nullable();
            $table->string('commission_trigger', 32)->nullable();
            $table->string('commission_trigger_description')->nullable();
            $table->unsignedSmallInteger('commission_payment_deadline_days')->nullable();
            $table->decimal('minimum_qualifying_amount', 15, 2)->nullable();
            $table->text('qualifying_conditions')->nullable();
            $table->text('refund_cancellation_rules')->nullable();

            $table->text('approved_claims')->nullable();
            $table->text('prohibited_claims')->nullable();
            $table->text('brand_use_rules')->nullable();
            $table->text('geographic_customer_restrictions')->nullable();
            $table->text('approved_copy')->nullable();
            $table->json('marketing_links')->nullable();

            $table->string('payment_destination_name')->nullable();
            $table->string('payment_provider')->nullable();
            $table->string('payment_account_identifier')->nullable();
            $table->text('payment_instructions')->nullable();
            $table->string('payment_contact')->nullable();

            $table->text('terms')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'version_number'], 'cv_campaign_version_unq');
            $table->index(['campaign_id', 'status'], 'cv_campaign_status_idx');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('current_campaign_version_id')
                ->nullable()
                ->constrained('campaign_versions', 'id', 'campaigns_current_version_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropForeign('campaigns_current_version_fk');
            $table->dropColumn('current_campaign_version_id');
        });

        Schema::dropIfExists('campaign_versions');
    }
};
