<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_marketing_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 32);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['campaign_id', 'sort_order'], 'camp_media_campaign_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_marketing_resources');
    }
};
