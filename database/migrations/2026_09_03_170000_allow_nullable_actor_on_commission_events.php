<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite does not support ALTER COLUMN. Drop and recreate.
            Schema::dropIfExists('commission_events');

            Schema::create('commission_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('commission_id')->constrained()->restrictOnDelete();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
                $table->string('type', 64);
                $table->string('previous_status', 32)->nullable();
                $table->string('new_status', 32);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['commission_id', 'type'], 'commission_events_commission_type_unq');
                $table->index(['commission_id', 'id'], 'commission_events_commission_id_idx');
            });

            return;
        }

        Schema::table('commission_events', function (Blueprint $table) {
            $table->dropForeign(['actor_user_id']);
        });

        DB::statement('ALTER TABLE commission_events MODIFY actor_user_id BIGINT UNSIGNED NULL');

        Schema::table('commission_events', function (Blueprint $table) {
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('commission_events', function (Blueprint $table) {
            $table->dropForeign(['actor_user_id']);
        });

        DB::statement('ALTER TABLE commission_events MODIFY actor_user_id BIGINT UNSIGNED NOT NULL');

        Schema::table('commission_events', function (Blueprint $table) {
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
