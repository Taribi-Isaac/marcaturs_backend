<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->string('idempotency_key', 255)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'notifications_idempotency_key_unq');
            $table->index('read_at', 'notifications_read_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
