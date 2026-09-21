<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')
                ->constrained('certification_enrollments')
                ->restrictOnDelete();
            $table->foreignId('assessment_id')
                ->constrained('certification_assessments')
                ->restrictOnDelete();
            $table->foreignId('programme_version_id')
                ->constrained('certification_programme_versions')
                ->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 32);
            $table->timestamp('started_at');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedInteger('correct_count')->nullable();
            $table->unsignedInteger('total_questions')->nullable();
            $table->decimal('score_percent', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->decimal('pass_mark_percent', 5, 2);
            $table->timestamps();

            $table->unique(['enrollment_id', 'attempt_number'], 'certification_attempts_enrollment_number_uidx');
            $table->index(['enrollment_id', 'status'], 'certification_attempts_enrollment_status_idx');
            $table->index(['assessment_id', 'enrollment_id'], 'certification_attempts_assessment_enrollment_idx');
            $table->index('programme_version_id', 'certification_attempts_version_idx');
        });

        Schema::create('certification_attempt_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')
                ->constrained('certification_assessment_attempts')
                ->restrictOnDelete();
            $table->foreignId('question_id')
                ->constrained('certification_questions')
                ->restrictOnDelete();
            $table->foreignId('selected_option_id')
                ->constrained('certification_question_options')
                ->restrictOnDelete();
            $table->boolean('is_correct')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id'], 'certification_attempt_answers_attempt_question_uidx');
            $table->index('question_id', 'certification_attempt_answers_question_idx');
            $table->index('selected_option_id', 'certification_attempt_answers_option_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_attempt_answers');
        Schema::dropIfExists('certification_assessment_attempts');
    }
};
