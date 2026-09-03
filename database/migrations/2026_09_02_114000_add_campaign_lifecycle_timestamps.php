<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('review_reason')->nullable();

            $table->index(['status', 'listing_expires_at'], 'campaigns_status_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('campaigns_status_expires_idx');
            $table->dropColumn([
                'submitted_at',
                'approved_at',
                'activated_at',
                'deactivated_at',
                'expired_at',
                'closed_at',
                'suspended_at',
                'review_reason',
            ]);
        });
    }
};
