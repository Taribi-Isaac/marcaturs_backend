<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('listing_status', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('name', 'categories_name_unq');
            $table->unique('slug', 'categories_slug_unq');
            $table->index(['is_active', 'listing_status', 'sort_order'], 'categories_visible_sort_idx');
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')
                ->constrained('categories', 'id', 'campaigns_category_fk')
                ->restrictOnDelete();
            $table->string('title');
            $table->string('status', 32);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('listing_starts_at')->nullable();
            $table->timestamp('listing_expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'campaigns_user_status_idx');
            $table->index(['category_id', 'status'], 'campaigns_category_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('categories');
    }
};
