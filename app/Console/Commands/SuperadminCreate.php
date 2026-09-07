<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperadminCreate extends Command
{
    protected $signature = 'id:superadmin-create {username}';

    protected $description = 'Create a Superadmin account with a freshly generated password';

    public function handle(AuditLogger $auditLogger): int
    {
        $username = $this->argument('username');

        if (User::where('username', $username)->exists()) {
            $this->components->error("A user with username \"{$username}\" already exists.");

            return self::FAILURE;
        }

        $password = Str::password(20);

        $user = User::create([
            'username' => $username,
            'name' => $username,
            'password' => Hash::make($password),
            'role' => Role::Superadmin,
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $auditLogger->log(
            actor: null,
            action: 'superadmin_created_via_console',
            subject: $user,
            newValue: ['username' => $user->username, ...AuditLogger::consoleProvenance()],
            actingAs: 'console',
        );

        $this->components->info("Superadmin \"{$user->username}\" created.");
        $this->line('Password (shown once, not stored anywhere else):');
        $this->line("  {$password}");
        $this->newLine();
        $this->comment('The account must change this password at first login.');

        return self::SUCCESS;
    }
}
