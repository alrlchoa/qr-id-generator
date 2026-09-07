<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;

class SuperadminList extends Command
{
    protected $signature = 'id:superadmin-list';

    protected $description = 'List all Superadmin accounts';

    public function handle(): int
    {
        $users = User::where('role', Role::Superadmin)->orderBy('username')->get();

        if ($users->isEmpty()) {
            $this->components->warn('No Superadmin accounts exist.');

            return self::SUCCESS;
        }

        $this->table(
            ['Username', 'Name', 'Active', 'Must change password', 'Last login'],
            $users->map(fn (User $user) => [
                $user->username,
                $user->name,
                $user->is_active ? 'yes' : 'no',
                $user->must_change_password ? 'yes' : 'no',
                $user->last_login_at?->toDateTimeString() ?? 'never',
            ]),
        );

        return self::SUCCESS;
    }
}
