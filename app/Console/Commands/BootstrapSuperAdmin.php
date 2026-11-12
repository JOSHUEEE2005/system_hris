<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapSuperAdmin extends Command
{
    protected $signature = 'hris:bootstrap-admin';

    protected $description = 'Create the first Super Admin account securely';

    public function handle(): int
    {
        $role = Role::where('slug', 'super-admin')->first();
        if (! $role) {
            $this->error('Roles are missing. Run the database seeders first.');

            return self::FAILURE;
        }

        if (User::whereHas('roles', fn ($query) => $query->where('slug', 'super-admin'))
            ->where('status', 'Active')->exists()) {
            $this->error('An active Super Admin already exists.');

            return self::FAILURE;
        }

        $name = $this->ask('Administrator name');
        $email = strtolower(trim($this->ask('Administrator email')));
        $password = $this->secret('Password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm password');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:150', 'unique:users,email'],
                'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
            ],
        );

        if ($validator->fails() || ! is_string($password) || ! hash_equals($password, (string) $confirmation)) {
            $this->error($validator->errors()->first() ?: 'The password confirmation does not match.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $password, $role): void {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password_hash' => Hash::make($password),
                'email_verified_at' => now(),
                'status' => 'Active',
            ]);
            $user->roles()->sync([$role->id]);
        });

        $this->info('Super Admin account created.');

        return self::SUCCESS;
    }
}
