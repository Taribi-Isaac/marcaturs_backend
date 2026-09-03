<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_extension_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('duration_days');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('NGN');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'ext_pkg_active_sort_idx');
        });

        Schema::create('platform_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('campaign_extension_package_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 64);
            $table->string('provider', 32)->default('paystack');
            $table->string('reference', 64);
            $table->string('provider_reference', 64)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedInteger('duration_days')->nullable();
            $table->string('status', 32);
            $table->text('authorization_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique('reference', 'plat_pay_reference_uidx');
            $table->unique('provider_reference', 'plat_pay_provider_ref_uidx');
            $table->index(['campaign_id', 'status'], 'plat_pay_campaign_status_idx');
            $table->index(['user_id', 'purpose'], 'plat_pay_user_purpose_idx');
        });

        Schema::create('campaign_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_extension_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('platform_payment_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('duration_days');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('previous_listing_expires_at')->nullable();
            $table->timestamp('resulting_listing_expires_at');
            $table->string('previous_status', 32);
            $table->string('resulting_status', 32);
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->unique('platform_payment_id', 'camp_ext_payment_uidx');
            $table->index('campaign_id', 'camp_ext_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_extensions');
        Schema::dropIfExists('platform_payments');
        Schema::dropIfExists('campaign_extension_packages');
    }
};
