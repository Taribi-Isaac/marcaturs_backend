<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('due_at');
            $table->timestamp('received_at')->nullable()->after('paid_at');
            $table->string('payment_reference', 128)->nullable()->after('received_at');
            $table->string('payment_note', 2000)->nullable()->after('payment_reference');
        });

        Schema::create('commission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 64);
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['commission_id', 'type'], 'commission_events_commission_type_unq');
            $table->index(['commission_id', 'id'], 'commission_events_commission_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_events');

        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'received_at', 'payment_reference', 'payment_note']);
        });
    }
};
