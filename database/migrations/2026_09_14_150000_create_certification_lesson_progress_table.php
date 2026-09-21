<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')
                ->constrained('certification_enrollments')
                ->restrictOnDelete();
            $table->foreignId('lesson_id')
                ->constrained('certification_lessons')
                ->restrictOnDelete();
            $table->string('status', 32);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'lesson_id'], 'certification_lesson_progress_enrollment_lesson_uidx');
            $table->index(['enrollment_id', 'status'], 'certification_lesson_progress_enrollment_status_idx');
            $table->index('lesson_id', 'certification_lesson_progress_lesson_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_lesson_progress');
    }
};
