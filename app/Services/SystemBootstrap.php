<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\SuperadminInvariantException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * First-run bootstrap (architecture §12).
 *
 * The system is bootstrapped from the browser: on first access, with no
 * active Superadmin in existence, the setup wizard creates the two the
 * §11 invariant requires. This is the one place in the system where an
 * unauthenticated HTTP request creates a privileged account, which is why
 * the precondition is checked here — inside the transaction — and not left
 * to the route or the middleware to remember.
 */
class SystemBootstrap
{
    /**
     * An arbitrary but fixed key identifying the bootstrap critical section.
     * Any constant works; it only has to be the same for every caller.
     */
    private const ADVISORY_LOCK_KEY = 4820157;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Is the system still waiting to be bootstrapped?
     *
     * "Bootstrapped" means at least one active Superadmin exists. A system
     * whose Superadmins have all been disabled is back here deliberately:
     * that is the state console break-glass (§12) also addresses, and the
     * wizard refusing to help would leave no route back in at all.
     */
    public function needsBootstrap(): bool
    {
        return ! User::query()
            ->where('role', Role::Superadmin)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Create both Superadmins in one transaction.
     *
     * All-or-nothing by design (§12): a wizard that could stop after one
     * account would hand the system straight into the one-member-tier state
     * the §11 invariant exists to prevent.
     *
     * Neither account gets `must_change_password` — the operator chose both
     * passwords directly in the browser, so there is nothing to rotate away
     * from.
     *
     * @param  array{username: string, name: string, password: string}  $first
     * @param  array{username: string, name: string, password: string}  $second
     * @return array<int, User>
     *
     * @throws SuperadminInvariantException
     */
    public function bootstrap(array $first, array $second): array
    {
        return DB::transaction(function () use ($first, $second) {
            // There are no Superadmin rows to lock — that is the whole
            // precondition — so lockForUpdate() has nothing to hold and two
            // simultaneous submissions could each pass the check below and
            // create a pair. A transaction-scoped advisory lock is the right
            // primitive when the thing being serialised is the *absence* of
            // rows. Released automatically at commit or rollback.
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::ADVISORY_LOCK_KEY]);

            if (! $this->needsBootstrap()) {
                throw new SuperadminInvariantException(
                    'The system has already been bootstrapped.'
                );
            }

            return [
                $this->createSuperadmin($first),
                $this->createSuperadmin($second),
            ];
        });
    }

    /**
     * @param  array{username: string, name: string, password: string}  $attributes
     */
    private function createSuperadmin(array $attributes): User
    {
        $user = User::create([
            'username' => $attributes['username'],
            'name' => $attributes['name'],
            'password' => $attributes['password'],
            'role' => Role::Superadmin,
            'must_change_password' => false,
            'is_active' => true,
        ]);

        // §12: user_id = null, user_role = 'setup_wizard'. ip_address comes
        // from AuditLogger reading the current request automatically — the
        // wizard is always reached over HTTP, so there is always one to read.
        $this->auditLogger->log(
            actor: null,
            action: 'superadmin_created_via_wizard',
            subject: $user,
            newValue: ['username' => $user->username],
            actingAs: 'setup_wizard',
        );

        return $user;
    }
}
