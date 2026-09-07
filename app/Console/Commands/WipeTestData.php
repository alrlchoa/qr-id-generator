<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;

/**
 * A full, irreversible reset — every domain table, `users` included —
 * empty afterward. The next visit to the app re-triggers the first-run
 * setup wizard (architecture §12), which is the point: this is for testing
 * bootstrap itself, not just re-seeding test data. `demo:seed-test-data`
 * is the narrower tool that leaves `users` alone.
 *
 * **Bypasses the app layer entirely, by raw `TRUNCATE`, deliberately —
 * including `audit_logs`/`security_events`.** CLAUDE.md rule 8 guards those
 * two tables against an *application* edit/delete path; it says nothing
 * about a human-invoked, environment-gated, whole-database reset tool,
 * which is the same category as `php artisan migrate:fresh` (already
 * capable of dropping and recreating both tables) rather than a feature
 * this app exposes. `AppendOnly`'s model-layer guard is correctly never
 * consulted here, the same way it's never consulted by a migration.
 *
 * Confirmation follows Laravel's own `ConfirmableTrait` — identical to
 * `migrate:fresh`: prompts (or requires `--force`) only when
 * `APP_ENV=production`, proceeds immediately in `local`. No custom
 * safety flag was invented here; this project's own deployed environment
 * is already `production`, so the built-in behavior is the right gate.
 */
class WipeTestData extends Command
{
    use ConfirmableTrait;

    protected $signature = 'db:wipe-test-data {--force : Force the operation to run in production}';

    protected $description = 'Truncate every table — users, people, units, relationships, cards, templates, and both logs — for a fresh test slate. Irreversible.';

    private const TABLES = [
        'sessions', 'users', 'id_cards', 'person_unit_relationships',
        'units', 'people', 'templates', 'audit_logs', 'security_events',
    ];

    public function handle(): int
    {
        if (! $this->confirmToProceed(
            'This permanently deletes every user, person, unit, relationship, card, template, and log row. There is no undo.'
        )) {
            return self::FAILURE;
        }

        DB::statement('TRUNCATE TABLE '.implode(', ', self::TABLES).' RESTART IDENTITY CASCADE');

        $this->components->info('Database wiped. All tables above are empty — the next visit to the app triggers the first-run setup wizard.');

        return self::SUCCESS;
    }
}
