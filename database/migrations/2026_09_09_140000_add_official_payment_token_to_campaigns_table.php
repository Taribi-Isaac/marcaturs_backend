<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('official_payment_token', 64)
                ->nullable()
                ->unique()
                ->after('review_reason');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropUnique(['official_payment_token']);
            $table->dropColumn('official_payment_token');
        });
    }
};
