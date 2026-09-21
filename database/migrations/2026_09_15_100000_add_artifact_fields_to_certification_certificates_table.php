<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certification_certificates', function (Blueprint $table) {
            $table->string('artifact_status', 32)->default('pending_generation')->after('issuer_name');
            $table->string('artifact_disk', 64)->nullable()->after('artifact_status');
            $table->string('artifact_path', 512)->nullable()->after('artifact_disk');
            $table->timestamp('artifact_generated_at')->nullable()->after('artifact_path');
            $table->timestamp('artifact_failed_at')->nullable()->after('artifact_generated_at');
            $table->string('artifact_error_code', 64)->nullable()->after('artifact_failed_at');

            $table->index('artifact_status', 'certification_certificates_artifact_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('certification_certificates', function (Blueprint $table) {
            $table->dropIndex('certification_certificates_artifact_status_idx');
            $table->dropColumn([
                'artifact_status',
                'artifact_disk',
                'artifact_path',
                'artifact_generated_at',
                'artifact_failed_at',
                'artifact_error_code',
            ]);
        });
    }
};
