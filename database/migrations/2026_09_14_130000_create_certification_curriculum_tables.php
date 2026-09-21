<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_version_id')
                ->constrained('certification_programme_versions')
                ->restrictOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['programme_version_id', 'sort_order'], 'certification_modules_version_sort_uq');
            $table->index(['programme_version_id', 'id'], 'certification_modules_version_id_idx');
        });

        Schema::create('certification_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')
                ->constrained('certification_modules')
                ->restrictOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('content_type', 32);
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['module_id', 'sort_order'], 'certification_lessons_module_sort_uq');
            $table->index(['module_id', 'id'], 'certification_lessons_module_id_idx');
            $table->index('content_type', 'certification_lessons_content_type_idx');
        });

        Schema::create('certification_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')
                ->constrained('certification_lessons')
                ->restrictOnDelete();
            $table->string('type', 32);
            $table->string('title', 255);
            $table->unsignedInteger('sort_order');
            $table->text('body_text')->nullable();
            $table->string('external_url', 2048)->nullable();
            $table->string('disk', 64)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 127)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamps();

            $table->unique(['lesson_id', 'sort_order'], 'certification_resources_lesson_sort_uq');
            $table->index(['lesson_id', 'id'], 'certification_resources_lesson_id_idx');
            $table->index('type', 'certification_resources_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_resources');
        Schema::dropIfExists('certification_lessons');
        Schema::dropIfExists('certification_modules');
    }
};
