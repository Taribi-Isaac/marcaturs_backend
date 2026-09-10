<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_covers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->unique('campaign_id', 'campaign_covers_campaign_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_covers');
    }
};
