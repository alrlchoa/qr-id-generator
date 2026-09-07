<?php

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Phase 4's own "done when": AuditLog::first()->update() throws, and every
 * console command produces a correctly-shaped row. The command-level half
 * is covered in SuperadminCommandsTest; this covers the guard itself, the
 * service that is the one call-site shape for every writer, and the same
 * guard on SecurityEvent (append-only for the same reason, CLAUDE.md rule 8).
 */
test('AuditLog rows cannot be updated', function () {
    $log = AuditLog::factory()->create();

    expect(fn () => $log->update(['action' => 'something_else']))
        ->toThrow(ImmutableRecordException::class);
});

test('AuditLog rows cannot be deleted', function () {
    $log = AuditLog::factory()->create();

    expect(fn () => $log->delete())
        ->toThrow(ImmutableRecordException::class);

    expect(AuditLog::find($log->id))->not->toBeNull();
});

test('SecurityEvent rows cannot be updated', function () {
    // A genuinely different value: the factory's own default is
    // login_failed, and Eloquent skips the updating event entirely (guard
    // included) when nothing is actually dirty.
    $event = SecurityEvent::factory()->create(['event_type' => 'login_failed']);

    expect(fn () => $event->update(['event_type' => 'qr_verify_miss']))
        ->toThrow(ImmutableRecordException::class);
});

test('SecurityEvent rows cannot be deleted', function () {
    $event = SecurityEvent::factory()->create();

    expect(fn () => $event->delete())
        ->toThrow(ImmutableRecordException::class);

    expect(SecurityEvent::find($event->id))->not->toBeNull();
});

test('logging an authenticated actor records their role and no override is needed', function () {
    $actor = User::factory()->admin()->create();
    $person = Person::factory()->create();

    $log = app(AuditLogger::class)->log(
        actor: $actor,
        action: 'person_created',
        subject: $person,
        newValue: ['first_name' => $person->first_name],
    );

    expect($log->user_id)->toBe($actor->id)
        ->and($log->user_role)->toBe('admin')
        ->and($log->action)->toBe('person_created')
        ->and($log->subject_type)->toBe($person->getMorphClass())
        ->and($log->subject_id)->toBe($person->id)
        ->and($log->new_value)->toBe(['first_name' => $person->first_name])
        ->and($log->previous_value)->toBeNull();
});

test('logging with a null actor requires an explicit actingAs', function () {
    $person = Person::factory()->create();

    expect(fn () => app(AuditLogger::class)->log(
        actor: null,
        action: 'person_created',
        subject: $person,
    ))->toThrow(InvalidArgumentException::class);
});

test('logging with a null actor and an explicit actingAs records that role and no user_id', function () {
    $person = Person::factory()->create();

    $log = app(AuditLogger::class)->log(
        actor: null,
        action: 'person_created',
        subject: $person,
        actingAs: 'console',
    );

    expect($log->user_id)->toBeNull()
        ->and($log->user_role)->toBe('console');
});

test('previous and new values are both recorded when both are given', function () {
    $actor = User::factory()->admin()->create();
    $target = User::factory()->reader()->create();

    $log = app(AuditLogger::class)->log(
        actor: $actor,
        action: 'role_changed',
        subject: $target,
        previousValue: ['role' => 'reader'],
        newValue: ['role' => 'admin'],
    );

    expect($log->previous_value)->toBe(['role' => 'reader'])
        ->and($log->new_value)->toBe(['role' => 'admin']);
});

test('consoleProvenance reports the running os user and hostname', function () {
    $provenance = AuditLogger::consoleProvenance();

    expect($provenance)->toHaveKeys(['os_user', 'hostname'])
        ->and($provenance['os_user'])->toBeString();
});
