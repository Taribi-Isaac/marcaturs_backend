<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MH-BE-048: Programme Version is the sole authoritative pass-mark source.
 * Assessment-level pass_mark_percent is removed to prevent dual-authority drift.
 * Attempt snapshots (certification_assessment_attempts.pass_mark_percent) are retained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certification_assessments', function (Blueprint $table): void {
            $table->dropColumn('pass_mark_percent');
        });
    }

    public function down(): void
    {
        Schema::table('certification_assessments', function (Blueprint $table): void {
            $table->decimal('pass_mark_percent', 5, 2)->nullable()->after('instructions');
        });
    }
};
