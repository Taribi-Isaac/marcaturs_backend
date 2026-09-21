<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('programme_id')
                ->constrained('certification_programmes')
                ->restrictOnDelete();
            $table->foreignId('programme_version_id')
                ->constrained('certification_programme_versions')
                ->restrictOnDelete();
            $table->foreignId('enrollment_id')
                ->constrained('certification_enrollments')
                ->restrictOnDelete();
            $table->foreignId('assessment_attempt_id')
                ->constrained('certification_assessment_attempts')
                ->restrictOnDelete();
            $table->string('status', 32);
            $table->timestamp('awarded_at');
            $table->timestamps();

            $table->unique(['user_id', 'programme_version_id'], 'certification_awards_user_version_uidx');
            $table->unique('assessment_attempt_id', 'certification_awards_attempt_uidx');
            $table->unique('enrollment_id', 'certification_awards_enrollment_uidx');
            $table->index(['user_id', 'programme_id'], 'certification_awards_user_programme_idx');
            $table->index('programme_id', 'certification_awards_programme_idx');
            $table->index('status', 'certification_awards_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_awards');
    }
};
