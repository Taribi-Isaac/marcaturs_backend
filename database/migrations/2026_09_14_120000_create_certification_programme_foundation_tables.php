<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_programmes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->text('learning_objectives')->nullable();
            $table->string('status', 32);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('current_published_version_id')->nullable();
            $table->timestamps();

            $table->index('status', 'certification_programmes_status_idx');
            $table->index('current_published_version_id', 'certification_programmes_current_version_idx');
        });

        Schema::create('certification_programme_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained('certification_programmes')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 32);
            $table->unsignedBigInteger('fee_amount_minor')->nullable();
            $table->string('fee_currency', 3)->default('NGN');
            $table->decimal('pass_mark_percent', 5, 2)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['programme_id', 'version_number'], 'certification_programme_versions_programme_version_uq');
            $table->index(['programme_id', 'status'], 'certification_programme_versions_programme_status_idx');
        });

        Schema::table('certification_programmes', function (Blueprint $table) {
            $table->foreign('current_published_version_id', 'certification_programmes_current_version_fk')
                ->references('id')
                ->on('certification_programme_versions')
                ->nullOnDelete();
        });

        Schema::create('certification_admin_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('programme_id')->constrained('certification_programmes')->cascadeOnDelete();
            $table->foreignId('programme_version_id')->nullable()->constrained('certification_programme_versions')->nullOnDelete();
            $table->string('action', 64);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['programme_id', 'id'], 'certification_admin_events_programme_id_idx');
            $table->index(['actor_user_id', 'id'], 'certification_admin_events_actor_id_idx');
            $table->index('action', 'certification_admin_events_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_admin_events');

        Schema::table('certification_programmes', function (Blueprint $table) {
            $table->dropForeign('certification_programmes_current_version_fk');
        });

        Schema::dropIfExists('certification_programme_versions');
        Schema::dropIfExists('certification_programmes');
    }
};
