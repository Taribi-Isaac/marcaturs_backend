<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ambassador_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['ambassador_user_id', 'campaign_id'],
                'campaign_bookmarks_owner_campaign_unq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_bookmarks');
    }
};
