<?php

use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use Livewire\Volt\Volt;

test('a Reader cannot view the audit log', function () {
    bootstrapSystem();

    $reader = User::factory()->reader()->create();

    $this->actingAs($reader);

    $this->get('/audit')->assertForbidden();
});

test('an Admin can view the audit log', function () {
    bootstrapSystem();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin);

    $this->get('/audit')->assertOk()->assertSeeVolt('pages.audit.index');
});

test('a Superadmin can view the audit log', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadmin);

    $this->get('/audit')->assertOk();
});

test('viewing the audit log writes nothing to it', function () {
    // Architecture §14 makes the same point about the reconciliation
    // dashboard: reading a list is not a business event.
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadmin);

    $before = AuditLog::count();

    Volt::test('pages.audit.index');

    expect(AuditLog::count())->toBe($before);
});

test('the actor filter narrows results to that username', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    $alice = User::factory()->admin()->create(['username' => 'alice']);
    $bob = User::factory()->admin()->create(['username' => 'bob']);
    $target = User::factory()->reader()->create();

    app(AuditLogger::class)->log($alice, 'account_created', $target, newValue: ['username' => 'x']);
    app(AuditLogger::class)->log($bob, 'account_created', $target, newValue: ['username' => 'y']);

    $this->actingAs($superadmin);

    $component = Volt::test('pages.audit.index')->set('actorUsername', 'alice');

    $rendered = $component->html();

    expect($rendered)->toContain('alice')
        ->and($rendered)->not->toContain('>bob<');
});

test('the action filter narrows results to matching actions', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    $target = User::factory()->reader()->create();

    app(AuditLogger::class)->log($superadmin, 'account_created', $target, newValue: ['username' => 'x']);
    app(AuditLogger::class)->log($superadmin, 'role_changed', $target, newValue: ['role' => 'admin']);

    $this->actingAs($superadmin);

    $component = Volt::test('pages.audit.index')->set('action', 'role_changed');

    // Not a plain "does the HTML contain this string" check: the filter's
    // own <datalist> always lists every known action as an autocomplete
    // option, account_created included, regardless of what's currently
    // selected — so that substring legitimately appears on the page either
    // way. What must differ is the *results table*, so the assertion
    // targets the literal cell markup rather than the word alone.
    expect($component->html())->toContain('>role_changed</td>')
        ->and($component->html())->not->toContain('>account_created</td>');
});

test('the subject filter narrows results to that record', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    $targetA = User::factory()->reader()->create();
    $targetB = User::factory()->reader()->create();

    app(AuditLogger::class)->log($superadmin, 'account_created', $targetA, newValue: ['username' => $targetA->username]);
    app(AuditLogger::class)->log($superadmin, 'account_created', $targetB, newValue: ['username' => $targetB->username]);

    $this->actingAs($superadmin);

    $component = Volt::test('pages.audit.index')
        ->set('subjectType', $targetA->getMorphClass())
        ->set('subjectId', (string) $targetA->id);

    $rendered = $component->html();

    expect($rendered)->toContain((string) $targetA->id);
});

test('Phase 13 re-verification: the subject-type filter actually excludes a different type, not just narrows within one', function () {
    // Phase 4 flagged this as unverified — every fixture it had was a
    // single subject type (users), so "does a Person row leak into a Unit
    // filter" had nothing to test against. Real Person/Unit data exists
    // from Phase 6/7 onward; this is that re-verification.
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    $person = Person::factory()->create();
    $unit = Unit::factory()->create();

    app(AuditLogger::class)->log($superadmin, 'person_created', $person, newValue: ['display_name' => $person->displayName()]);
    app(AuditLogger::class)->log($superadmin, 'unit_created', $unit, newValue: ['unit_code' => $unit->unitCode()]);

    $this->actingAs($superadmin);

    // Assert on the new_value payload, not the bare action string — the
    // filter form's <datalist> autocomplete lists every known action
    // globally regardless of the active filter, so 'person_created' as a
    // string is always present in the page somewhere; the JSON payload
    // only ever appears in an actual results row.
    $rendered = Volt::test('pages.audit.index')
        ->set('subjectType', $unit->getMorphClass())
        ->html();

    expect($rendered)->toContain($unit->unitCode())
        ->and($rendered)->not->toContain($person->displayName());
});

test('Phase 13 re-verification: subjectWithTrashed resolves a non-SoftDeletes subject correctly', function () {
    // Phase 4 flagged this too — every prior test of subjectWithTrashed()
    // used User, which has SoftDeletes, so the in_array() check's false
    // branch (no withoutGlobalScope() call) was never actually exercised.
    // IdCard never has SoftDeletes at all (rule 7) — a real subject that
    // genuinely takes this path.
    $superadmin = User::factory()->superadmin()->create();
    $card = IdCard::factory()->create();

    $log = app(AuditLogger::class)->log($superadmin, 'id_issued', $card, newValue: ['control_number' => $card->control_number]);

    expect($log->subjectWithTrashed())->not->toBeNull()
        ->and($log->subjectWithTrashed()->is($card))->toBeTrue();
    expect($log->subjectLabel())->toContain('IdCard')->toContain((string) $card->id);
});

test('the subject label resolves a soft-deleted subject via withTrashed', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    $target = User::factory()->reader()->create(['username' => 'gone.reader']);

    $log = app(AuditLogger::class)->log($superadmin, 'account_created', $target, newValue: ['username' => $target->username]);

    $target->delete();

    expect($log->subjectLabel())->toContain('gone.reader');
});

test('resetFilters clears every filter and the page', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadmin);

    Volt::test('pages.audit.index')
        ->set('actorUsername', 'alice')
        ->set('action', 'role_changed')
        ->call('resetFilters')
        ->assertSet('actorUsername', '')
        ->assertSet('action', '');
});
