<?php

use App\Exceptions\SuperadminInvariantException;
use App\Models\User;
use App\Services\UserAccountManager;
use Illuminate\Support\Facades\DB;

/**
 * These tests open a second, independent database connection so a lock
 * held by one "actor" is genuinely still held while a second actor probes
 * it -- not merely simulated by re-reading a count in the same process.
 * That needs real committed rows visible across connections, so this
 * directory opts out of the project-wide RefreshDatabase wrapper (see
 * tests/Pest.php, which binds it only under Feature) and cleans up its own
 * rows instead.
 */
afterEach(function () {
    // Phase 4: disable()/changeRole() now write audit_logs rows attributed
    // to these fixture users, and that FK has no cascade — the referencing
    // rows have to go first, or deleting a still-referenced user violates
    // it. audit_logs is append-only everywhere else in the app (CLAUDE.md
    // rule 8); this raw delete is test cleanup, not a business path, the
    // same way the raw user delete below already was.
    $testUserIds = DB::table('users')->where('username', 'like', 'concurrency-test-%')->pluck('id');
    DB::table('audit_logs')->whereIn('user_id', $testUserIds)->delete();
    DB::table('users')->where('username', 'like', 'concurrency-test-%')->delete();
});

test('the Superadmin row lock genuinely blocks a second concurrent attempt', function () {
    $x = User::factory()->superadmin()->create(['username' => 'concurrency-test-x']);
    $y = User::factory()->superadmin()->create(['username' => 'concurrency-test-y']);
    User::factory()->superadmin()->create(['username' => 'concurrency-test-z']);

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
    $x = User::factory()->superadmin()->create(['username' => 'concurrency-test-x']);
    $y = User::factory()->superadmin()->create(['username' => 'concurrency-test-y']);
    $actor = User::factory()->superadmin()->create(['username' => 'concurrency-test-actor']);

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
