<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_version_id')
                ->constrained('certification_programme_versions')
                ->restrictOnDelete();
            $table->string('title', 255);
            $table->text('instructions')->nullable();
            $table->decimal('pass_mark_percent', 5, 2)->nullable();
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique('programme_version_id', 'certification_assessments_version_uidx');
        });

        Schema::create('certification_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')
                ->constrained('certification_assessments')
                ->restrictOnDelete();
            $table->text('prompt');
            $table->string('type', 32);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['assessment_id', 'sort_order'], 'certification_questions_assessment_sort_uq');
            $table->index(['assessment_id', 'id'], 'certification_questions_assessment_id_idx');
            $table->index('type', 'certification_questions_type_idx');
        });

        Schema::create('certification_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')
                ->constrained('certification_questions')
                ->restrictOnDelete();
            $table->string('label', 1000);
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['question_id', 'sort_order'], 'certification_question_options_question_sort_uq');
            $table->index(['question_id', 'id'], 'certification_question_options_question_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_question_options');
        Schema::dropIfExists('certification_questions');
        Schema::dropIfExists('certification_assessments');
    }
};
