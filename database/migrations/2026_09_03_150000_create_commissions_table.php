<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('ambassador_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('campaign_version_id')->constrained('campaign_versions')->restrictOnDelete();
            $table->string('status', 32);
            $table->string('commission_type', 32);
            $table->decimal('commission_rate', 5, 2)->nullable();
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->timestamp('became_due_at');
            $table->timestamp('due_at');
            $table->timestamps();

            $table->unique('deal_id', 'commissions_deal_id_unq');
            $table->index('business_user_id', 'commissions_business_user_idx');
            $table->index('ambassador_user_id', 'commissions_ambassador_user_idx');
            $table->index(['status', 'due_at'], 'commissions_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions');
    }
};
