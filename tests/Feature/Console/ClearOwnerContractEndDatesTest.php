<?php

use App\Models\AuditLog;
use App\Models\PersonUnitRelationship;

test('it clears contract_end_date on every owner relationship and logs each change', function () {
    $primary = PersonUnitRelationship::factory()->primaryOwner()->create(['contract_end_date' => '2026-01-01']);
    $coOwner = PersonUnitRelationship::factory()->create(['type' => 'owner', 'is_primary_owner' => false, 'contract_end_date' => '2026-02-01']);
    $tenant = PersonUnitRelationship::factory()->create(['type' => 'tenant', 'start_date' => '2020-01-01', 'contract_end_date' => '2026-03-01']);

    $this->artisan('relationships:clear-owner-contract-dates')->assertSuccessful();

    expect($primary->fresh()->contract_end_date)->toBeNull();
    expect($coOwner->fresh()->contract_end_date)->toBeNull();
    expect($tenant->fresh()->contract_end_date->format('Y-m-d'))->toBe('2026-03-01');

    expect(AuditLog::where('action', 'relationship_contract_end_date_updated')->where('subject_id', $primary->id)->exists())->toBeTrue();
    expect(AuditLog::where('action', 'relationship_contract_end_date_updated')->where('subject_id', $coOwner->id)->exists())->toBeTrue();
});

test('it is a no-op when nothing needs fixing', function () {
    PersonUnitRelationship::factory()->primaryOwner()->create(['contract_end_date' => null]);

    $before = AuditLog::count();

    $this->artisan('relationships:clear-owner-contract-dates')->assertSuccessful();

    expect(AuditLog::count())->toBe($before);
});
