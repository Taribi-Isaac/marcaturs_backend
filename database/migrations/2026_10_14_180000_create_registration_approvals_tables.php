<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_approvals', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            // Equals `email` while pending or consumed and NULL once revoked, so the unique
            // index allows at most one non-revoked approval per email without partial indexes.
            $table->string('active_email')->nullable();
            $table->string('role', 32);
            $table->string('status', 32);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('consumed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->unique('active_email', 'registration_approvals_active_email_unique');
            $table->index('email', 'registration_approvals_email_idx');
            $table->index(['status', 'id'], 'registration_approvals_status_id_idx');
        });

        Schema::create('registration_approval_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_approval_id')->constrained('registration_approvals')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['registration_approval_id', 'id'], 'registration_approval_events_approval_id_idx');
            $table->index(['actor_user_id', 'id'], 'registration_approval_events_actor_id_idx');
            $table->index('action', 'registration_approval_events_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_approval_events');
        Schema::dropIfExists('registration_approvals');
    }
};
