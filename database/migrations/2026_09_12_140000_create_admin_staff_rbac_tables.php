<?php

use App\Enums\AdminStaffRole;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('staff_role', 32);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('staff_role', 'admin_staff_profiles_staff_role_idx');
        });

        Schema::create('admin_staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('name');
            $table->string('staff_role', 32);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('email', 'admin_staff_invitations_email_idx');
            $table->index(['expires_at', 'accepted_at', 'revoked_at'], 'admin_staff_invitations_state_idx');
        });

        Schema::create('admin_staff_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('invitation_id')->nullable();
            $table->string('action', 32);
            $table->string('previous_staff_role', 32)->nullable();
            $table->string('new_staff_role', 32)->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['target_user_id', 'id'], 'admin_staff_events_target_id_idx');
            $table->index(['actor_user_id', 'id'], 'admin_staff_events_actor_id_idx');
            $table->index('action', 'admin_staff_events_action_idx');
        });

        Schema::create('campaign_admin_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'id'], 'campaign_admin_events_campaign_id_idx');
            $table->index(['actor_user_id', 'id'], 'campaign_admin_events_actor_id_idx');
            $table->index('action', 'campaign_admin_events_action_idx');
        });

        $now = now();
        $adminIds = DB::table('users')->where('role', Role::Admin->value)->orderBy('id')->pluck('id');

        foreach ($adminIds as $adminId) {
            $exists = DB::table('admin_staff_profiles')->where('user_id', $adminId)->exists();
            if ($exists) {
                continue;
            }

            DB::table('admin_staff_profiles')->insert([
                'user_id' => $adminId,
                'staff_role' => AdminStaffRole::SuperAdmin->value,
                'created_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_admin_events');
        Schema::dropIfExists('admin_staff_events');
        Schema::dropIfExists('admin_staff_invitations');
        Schema::dropIfExists('admin_staff_profiles');
    }
};
