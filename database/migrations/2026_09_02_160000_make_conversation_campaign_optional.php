<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('conversations', 'business_user_id')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->foreignId('business_user_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('users')
                    ->restrictOnDelete();
            });
        }

        foreach (DB::table('conversations')->whereNotNull('campaign_id')->cursor() as $row) {
            $businessUserId = DB::table('campaigns')->where('id', $row->campaign_id)->value('user_id');

            DB::table('conversations')->where('id', $row->id)->update([
                'business_user_id' => $businessUserId,
            ]);
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
        });

        $indexNames = collect(Schema::getIndexes('conversations'))->pluck('name');

        if ($indexNames->contains('conversations_campaign_ambassador_uq')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropUnique('conversations_campaign_ambassador_uq');
            });
        }

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->change();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('campaign_id')->references('id')->on('campaigns')->restrictOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('business_user_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropForeign(['business_user_id']);
            $table->dropColumn('business_user_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable(false)->change();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('campaign_id')->references('id')->on('campaigns')->restrictOnDelete();
            $table->unique(['campaign_id', 'ambassador_user_id'], 'conversations_campaign_ambassador_uq');
        });
    }
};
