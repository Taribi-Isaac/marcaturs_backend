<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_payments', function (Blueprint $table) {
            $table->foreignId('certification_programme_id')
                ->nullable()
                ->after('campaign_featured_package_id')
                ->constrained('certification_programmes')
                ->restrictOnDelete();

            $table->foreignId('certification_programme_version_id')
                ->nullable()
                ->after('certification_programme_id')
                ->constrained('certification_programme_versions')
                ->restrictOnDelete();
        });

        Schema::create('certification_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('programme_id')->constrained('certification_programmes')->restrictOnDelete();
            $table->foreignId('programme_version_id')->constrained('certification_programme_versions')->restrictOnDelete();
            $table->foreignId('platform_payment_id')->constrained('platform_payments')->restrictOnDelete();
            $table->string('status', 32);
            $table->unsignedBigInteger('fee_amount_minor');
            $table->char('fee_currency', 3);
            $table->timestamp('enrolled_at');
            $table->timestamps();

            $table->unique('platform_payment_id', 'certification_enrollments_payment_uidx');
            $table->unique(['user_id', 'programme_id'], 'certification_enrollments_user_programme_uidx');
            $table->index(['user_id', 'status'], 'certification_enrollments_user_status_idx');
            $table->index(['programme_id', 'status'], 'certification_enrollments_programme_status_idx');
            $table->index('programme_version_id', 'certification_enrollments_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_enrollments');

        Schema::table('platform_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certification_programme_version_id');
            $table->dropConstrainedForeignId('certification_programme_id');
        });
    }
};
