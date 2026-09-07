<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_featured_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('duration_days');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('NGN');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'feat_pkg_active_sort_idx');
        });

        Schema::table('platform_payments', function (Blueprint $table) {
            $table->foreignId('campaign_featured_package_id')
                ->nullable()
                ->after('campaign_extension_package_id')
                ->constrained('campaign_featured_packages')
                ->nullOnDelete();
        });

        Schema::create('campaign_featured_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_featured_package_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('platform_payment_id')->constrained()->restrictOnDelete();
            $table->string('package_name');
            $table->unsignedInteger('duration_days');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('activated_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique('platform_payment_id', 'camp_feat_payment_uidx');
            $table->index(['campaign_id', 'expires_at'], 'camp_feat_campaign_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_featured_purchases');

        Schema::table('platform_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_featured_package_id');
        });

        Schema::dropIfExists('campaign_featured_packages');
    }
};
