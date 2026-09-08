<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_status_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('previous_status', 32);
            $table->string('new_status', 32);
            $table->text('reason');
            $table->timestamps();

            $table->index(['target_user_id', 'id'], 'user_status_events_target_id_idx');
            $table->index(['actor_user_id', 'id'], 'user_status_events_actor_id_idx');
            $table->index('action', 'user_status_events_action_idx');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'status', 'id'], 'users_role_status_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_status_id_idx');
        });

        Schema::dropIfExists('user_status_events');
    }
};
