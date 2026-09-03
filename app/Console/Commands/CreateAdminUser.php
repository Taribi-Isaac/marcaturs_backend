<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\Rules\Password;

class CreateAdminUser extends Command
{
    protected $signature = 'marcaturs:create-admin
                            {email : Unique administrator email}
                            {--name=Administrator : Display name}
                            {--password= : Password (will be prompted if omitted)}';

    protected $description = 'Provision an ADMIN user. There is no public admin registration endpoint.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = (string) $this->option('name');
        $password = $this->option('password') ?: $this->secret('Password');

        if (! is_string($password) || $password === '') {
            $this->error('A password is required.');

            return self::FAILURE;
        }

        $validator = validator(
            [
                'email' => $email,
                'name' => $name,
                'password' => $password,
            ],
            [
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', Password::defaults()],
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
        $user->save();

        $this->info("Admin user created: {$user->email} (id {$user->id}).");
        $this->comment('Production provisioning of administrators is not yet specified in the foundational documents. Use this command from a controlled operator environment; do not expose it as a public API.');

        return self::SUCCESS;
    }
}
