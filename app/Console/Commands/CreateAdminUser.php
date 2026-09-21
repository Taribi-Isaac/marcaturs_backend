<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\AdminStaffRole;
use App\Enums\Role;
use App\Models\User;
use App\Services\Admin\AdminStaffService;
use Illuminate\Console\Command;
use Illuminate\Validation\Rules\Password;

class CreateAdminUser extends Command
{
    protected $signature = 'marcaturs:create-admin
                            {email : Unique administrator email}
                            {--name=Administrator : Display name}
                            {--password= : Password (will be prompted if omitted)}
                            {--staff-role=SUPER_ADMIN : Admin staff role (SUPER_ADMIN|OPERATIONS|VERIFICATION|MODERATION)}';

    protected $description = 'Provision an ADMIN user with an AdminStaffProfile. There is no public admin registration endpoint.';

    public function handle(AdminStaffService $staffService): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = (string) $this->option('name');
        $password = $this->option('password') ?: $this->secret('Password');
        $staffRoleRaw = strtoupper((string) $this->option('staff-role'));

        if (! is_string($password) || $password === '') {
            $this->error('A password is required.');

            return self::FAILURE;
        }

        $validator = validator(
            [
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'staff_role' => $staffRoleRaw,
            ],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', Password::defaults()],
                'staff_role' => ['required', 'in:'.implode(',', AdminStaffRole::values())],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->role = Role::Admin;
        $user->status = AccountStatus::Active;
        $user->email_verified_at = now();
        $user->save();

        $staffRole = AdminStaffRole::from($staffRoleRaw);
        $profile = $staffService->ensureSuperAdminProfile($user);
        if ($staffRole !== AdminStaffRole::SuperAdmin) {
            $profile->staff_role = $staffRole;
            $profile->save();
        }

        $this->info("Admin user created: {$user->email} (id {$user->id}, staff_role {$staffRole->value}).");
        $this->comment('Use invitation flow for normal staff onboarding. This command is break-glass provisioning.');

        return self::SUCCESS;
    }
}
