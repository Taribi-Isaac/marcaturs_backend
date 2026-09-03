<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('participant_type', 32);
            $table->string('requirement_type', 32);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['participant_type', 'is_active', 'sort_order'], 'vr_req_type_active_sort_idx');
        });

        Schema::create('verification_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verification_requirement_id')
                ->constrained('verification_requirements', 'id', 'vr_sub_requirement_fk')
                ->restrictOnDelete();
            $table->string('status', 32);
            $table->text('text_value')->nullable();
            $table->unsignedInteger('current_version')->default(1);
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users', 'id', 'vr_sub_reviewed_by_fk')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['user_id', 'verification_requirement_id'], 'vr_sub_user_requirement_unq');
            $table->index('status', 'vr_sub_status_idx');
        });

        Schema::create('verification_submission_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_submission_id')
                ->constrained('verification_submissions', 'id', 'vr_ver_submission_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('text_value')->nullable();
            $table->string('status', 32);
            $table->timestamps();

            $table->unique(['verification_submission_id', 'version'], 'vr_ver_submission_version_unq');
        });

        Schema::create('verification_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_submission_id')
                ->constrained('verification_submissions', 'id', 'vr_ev_submission_fk')
                ->cascadeOnDelete();
            $table->foreignId('verification_submission_version_id')
                ->constrained('verification_submission_versions', 'id', 'vr_ev_version_fk')
                ->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });

        Schema::create('verification_review_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verification_submission_id')
                ->constrained('verification_submissions', 'id', 'vr_evnt_submission_fk')
                ->cascadeOnDelete();
            $table->foreignId('verification_submission_version_id')
                ->nullable()
                ->constrained('verification_submission_versions', 'id', 'vr_evnt_version_fk')
                ->nullOnDelete();
            $table->foreignId('actor_id')
                ->nullable()
                ->constrained('users', 'id', 'vr_evnt_actor_fk')
                ->nullOnDelete();
            $table->string('action', 64);
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->text('reason')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->timestamps();

            $table->index(['verification_submission_id', 'created_at'], 'vr_evnt_submission_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_review_events');
        Schema::dropIfExists('verification_evidence');
        Schema::dropIfExists('verification_submission_versions');
        Schema::dropIfExists('verification_submissions');
        Schema::dropIfExists('verification_requirements');
    }
};
