<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->restrictOnDelete();
            $table->foreignId('ambassador_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 64);
            $table->string('status', 32);
            $table->string('reference_number', 128)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->date('paid_on')->nullable();
            $table->text('note')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 127)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['deal_id', 'id'], 'payment_evidence_deal_id_idx');
            $table->index('ambassador_user_id', 'payment_evidence_ambassador_idx');
            $table->index(['status', 'id'], 'payment_evidence_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_evidence');
    }
};
