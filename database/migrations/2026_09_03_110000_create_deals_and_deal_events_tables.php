<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ambassador_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_version_id')->constrained('campaign_versions')->restrictOnDelete();
            $table->string('status', 32);

            $table->string('product_name');
            $table->string('pricing_method', 32)->nullable();
            $table->decimal('price_amount', 15, 2)->nullable();
            $table->char('price_currency', 3)->nullable();
            $table->string('commission_type', 32);
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('commission_amount', 15, 2)->nullable();
            $table->string('commission_trigger', 32);
            $table->string('commission_trigger_description')->nullable();
            $table->unsignedSmallInteger('commission_payment_deadline_days');
            $table->decimal('minimum_qualifying_amount', 15, 2)->nullable();
            $table->text('qualifying_conditions')->nullable();
            $table->decimal('expected_transaction_amount', 15, 2)->nullable();
            $table->timestamps();

            $table->index('business_user_id', 'deals_business_user_idx');
            $table->index('ambassador_user_id', 'deals_ambassador_user_idx');
            $table->index(['campaign_id', 'id'], 'deals_campaign_id_idx');
            $table->index('campaign_version_id', 'deals_campaign_version_idx');
            $table->index(['status', 'id'], 'deals_status_idx');
        });

        Schema::create('deal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 64);
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['deal_id', 'id'], 'deal_events_deal_id_idx');
            $table->index('type', 'deal_events_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_events');
        Schema::dropIfExists('deals');
    }
};
