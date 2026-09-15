<?php

use App\Exceptions\SuperadminInvariantException;
use App\Models\User;
use App\Services\UserAccountManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * These tests open a second, independent database connection so a lock
 * held by one "actor" is genuinely still held while a second actor probes
 * it -- not merely simulated by re-reading a count in the same process.
 * That needs real committed rows visible across connections, so this
 * directory opts out of the project-wide RefreshDatabase wrapper (see
 * tests/Pest.php, which binds it only under Feature) and leaves its
 * fixture rows committed rather than cleaning them up.
 *
 * Phase 13, 2026-09-15: this used to clean up after itself with a raw
 * DB::table('audit_logs')->delete() (disable()/changeRole() write audit
 * rows attributed to these fixture users, and the FK has no cascade — the
 * referencing rows had to go first). That delete now fails outright: the
 * append-only DB trigger from this phase (CLAUDE.md rule 63) fires
 * regardless of caller, raw SQL included, and neither this app's DB role
 * nor any role short of a Postgres superuser can bypass a trigger — which
 * is the entire point of adding one. There is no legitimate way around it,
 * so the fix isn't a workaround, it's not needing the delete: every
 * username below carries a random per-run suffix so fixture rows from
 * different runs never collide on the unique username constraint, and
 * this file simply stops trying to delete anything. Accumulating a
 * handful of harmless rows per run in the test database is an acceptable
 * cost for a guarantee this absolute.
 *
 * That alone isn't quite enough for the second test below, though: it
 * asserts on the *global* count of active Superadmins ("only two remain"),
 * which a leftover active Superadmin from an earlier run — this file's
 * own past runs, or anything else created in this database — would throw
 * off without ever touching a username. A plain UPDATE on `users` isn't
 * guarded by either of this phase's triggers (only audit_logs/
 * security_events DELETE+UPDATE and person_unit_relationships writes are),
 * so beforeEach deactivates every pre-existing Superadmin first, giving
 * each test a deterministic zero-active baseline to build its own fixture
 * count on top of.
 */
beforeEach(function () {
    DB::table('users')->where('role', 'superadmin')->update(['is_active' => false]);
});

test('the Superadmin row lock genuinely blocks a second concurrent attempt', function () {
    $suffix = Str::random(8);
    $x = User::factory()->superadmin()->create(['username' => "concurrency-test-x-{$suffix}"]);
    $y = User::factory()->superadmin()->create(['username' => "concurrency-test-y-{$suffix}"]);
    User::factory()->superadmin()->create(['username' => "concurrency-test-z-{$suffix}"]);

    $config = config('database.connections.pgsql');
    $secondConnection = new PDO(
        "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
        $config['username'],
        $config['password'],
    );
    $secondConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Actor 1: start a transaction and take the same lockForUpdate() the
    // service takes, but don't commit yet -- this stands in for one
    // in-flight "disable" call that has entered its transaction.
    DB::beginTransaction();
    DB::table('users')->where('role', 'superadmin')->where('is_active', true)->lockForUpdate()->get();

    // Actor 2: a genuinely separate connection/session tries to read the
    // same rows FOR UPDATE. With actor 1's transaction still open, this
    // must fail to acquire the lock rather than silently proceeding on a
    // stale view of who's active.
    $secondConnection->exec('SET lock_timeout = 200');
    $secondConnection->beginTransaction();

    $blocked = false;

    try {
        $secondConnection->query(
            "SELECT * FROM users WHERE role = 'superadmin' AND is_active = true FOR UPDATE"
        );
    } catch (PDOException $e) {
        $blocked = true;
    }

    $secondConnection->rollBack();

    expect($blocked)->toBeTrue();

    // Release actor 1's lock -- this discards the manual lockForUpdate
    // probe, not any of the fixture rows (they were committed on creation,
    // outside this transaction).
    DB::rollBack();
});

test('once the lock is free, the second actor sees committed state and the invariant still holds', function () {
    $suffix = Str::random(8);
    $x = User::factory()->superadmin()->create(['username' => "concurrency-test-x-{$suffix}"]);
    $y = User::factory()->superadmin()->create(['username' => "concurrency-test-y-{$suffix}"]);
    $actor = User::factory()->superadmin()->create(['username' => "concurrency-test-actor-{$suffix}"]);

    $manager = app(UserAccountManager::class);

    // Actor 1's disable of X runs to completion (lock acquired, checked,
    // released on commit) before actor 2's attempt on Y is even considered.
    $manager->disable($actor, $x);

    expect($x->refresh()->is_active)->toBeFalse();

    // Actor 2 now acquires the same lock actor 1 held a moment ago. It must
    // see actor 1's committed change (only two active Superadmins remain:
    // Y and actor) and be refused -- proving the lock/recount pair carries
    // the up-to-date state across the handoff, not a value cached before
    // actor 1 ran.
    expect(fn () => $manager->disable($actor, $y))
        ->toThrow(SuperadminInvariantException::class);

    expect($y->refresh()->is_active)->toBeTrue();
});
