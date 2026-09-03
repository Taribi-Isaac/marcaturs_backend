<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->decimal('confirmed_payment_amount', 15, 2)->nullable()->after('expected_transaction_amount');
            $table->timestamp('confirmed_at')->nullable()->after('confirmed_payment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['confirmed_payment_amount', 'confirmed_at']);
        });
    }
};
