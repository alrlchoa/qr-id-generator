<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * No seeder creates a default account (CLAUDE.md rule 25) — use
     * `id:superadmin-create` instead.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            abort(500, 'Seeding is only permitted in the local environment.');
        }
    }
}
