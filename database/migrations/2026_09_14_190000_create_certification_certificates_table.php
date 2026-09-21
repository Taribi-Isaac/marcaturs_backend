<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('award_id')
                ->constrained('certification_awards')
                ->restrictOnDelete();
            $table->string('certificate_number', 64);
            $table->string('status', 32);
            $table->timestamp('issued_at');
            $table->string('recipient_name', 255);
            $table->string('programme_name', 255);
            $table->unsignedInteger('programme_version_number');
            $table->string('issuer_name', 255);
            $table->timestamps();

            $table->unique('award_id', 'certification_certificates_award_uidx');
            $table->unique('certificate_number', 'certification_certificates_number_uidx');
            $table->index('status', 'certification_certificates_status_idx');
            $table->index('issued_at', 'certification_certificates_issued_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_certificates');
    }
};
