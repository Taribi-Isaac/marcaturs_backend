<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'role' => Role::Business,
            'status' => AccountStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Admin,
        ])->afterCreating(function (User $user): void {
            if (! $user->isAdmin() || $user->adminStaffProfile()->exists()) {
                return;
            }

            $user->adminStaffProfile()->create([
                'staff_role' => AdminStaffRole::SuperAdmin,
                'created_by_user_id' => null,
            ]);
        });
    }

    public function adminStaff(AdminStaffRole $role): static
    {
        return $this->admin()->afterCreating(function (User $user) use ($role): void {
            $user->adminStaffProfile()->update(['staff_role' => $role]);
        });
    }

    public function business(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Business,
        ]);
    }

    public function ambassador(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Ambassador,
        ]);
    }

    public function restricted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Restricted,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Suspended,
        ]);
    }

    public function banned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AccountStatus::Banned,
        ]);
    }
}
