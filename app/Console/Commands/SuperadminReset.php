<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperadminReset extends Command
{
    protected $signature = 'id:superadmin-reset {username}';

    protected $description = 'Generate a fresh password for an existing Superadmin account (break-glass)';

    public function handle(): int
    {
        $username = $this->argument('username');

        $user = User::where('username', $username)->where('role', Role::Superadmin)->first();

        if (! $user) {
            $this->components->error("No Superadmin with username \"{$username}\" was found.");

            return self::FAILURE;
        }

        $password = Str::password(20);

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ])->save();

        $this->components->info("Password reset for Superadmin \"{$user->username}\".");
        $this->line('Password (shown once, not stored anywhere else):');
        $this->line("  {$password}");
        $this->newLine();
        $this->comment('The account must change this password at next login.');

        return self::SUCCESS;
    }
}
