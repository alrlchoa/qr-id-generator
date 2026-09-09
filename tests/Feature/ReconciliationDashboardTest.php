<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Services\UnitLifecycleManager;
use Livewire\Volt\Volt;

/**
 * Access control and the read-only guarantee (architecture §14). The four
 * queries themselves are covered in ReconciliationQueriesTest — this file
 * is about the screen: who can reach it, and that reaching it changes
 * nothing.
 */
test('a Reader cannot view the reconciliation dashboard', function () {
    bootstrapSystem();

    $reader = User::factory()->reader()->create();

    $this->actingAs($reader)->get('/reconciliation')->assertForbidden();
});

test('an Admin can view the reconciliation dashboard', function () {
    bootstrapSystem();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/reconciliation')->assertOk()->assertSeeVolt('pages.reconciliation.index');
});

test('a Superadmin can view the reconciliation dashboard', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();

    $this->actingAs($superadmin)->get('/reconciliation')->assertOk();
});

test('viewing the reconciliation dashboard writes nothing to audit_logs', function () {
    $superadmin = User::factory()->superadmin()->create();
    User::factory()->superadmin()->create();
    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    app(UnitLifecycleManager::class)->createUnit(
        $superadmin,
        ['building_code' => 'A', 'floor_code' => '01', 'unit_number' => '01'],
        ['person_id' => $owner->id],
        '2026-01-01',
    );

    $this->actingAs($superadmin);
    $before = AuditLog::count();

    Volt::test('pages.reconciliation.index');

    expect(AuditLog::count())->toBe($before);
});

test('the dashboard is linked from the nav for Admin and Superadmin but not Reader', function () {
    bootstrapSystem();

    $reader = User::factory()->reader()->create();
    $readerHtml = $this->actingAs($reader)->get('/dashboard')->assertOk()->getContent();
    expect($readerHtml)->not->toContain('Reconciliation');

    $admin = User::factory()->admin()->create();
    $adminHtml = $this->actingAs($admin)->get('/dashboard')->assertOk()->getContent();
    expect($adminHtml)->toContain('Reconciliation');
});
