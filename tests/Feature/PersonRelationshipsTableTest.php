<?php

use App\Models\AuditLog;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use Livewire\Volt\Volt;

test('editing a relationship\'s contract end date from the person page saves and is audit-logged', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->create([
        'person_id' => $person->id, 'type' => 'tenant', 'contract_end_date' => '2026-01-01',
    ]);

    Volt::test('pages.people.show', ['person' => $person])
        ->call('openEditContractEndDate', $relationship->id)
        ->assertSet('editContractEndDate', '2026-01-01')
        ->set('editContractEndDate', '2027-06-30')
        ->call('saveContractEndDate')
        ->assertHasNoErrors();

    expect($relationship->fresh()->contract_end_date->format('Y-m-d'))->toBe('2027-06-30');
    expect(AuditLog::where('action', 'relationship_contract_end_date_updated')->where('subject_id', $relationship->id)->exists())->toBeTrue();
});

test('editing a primary-owner relationship\'s contract end date is still allowed, unlike Close', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();
    $primary = PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $person->id]);

    Volt::test('pages.people.show', ['person' => $person])
        ->call('openEditContractEndDate', $primary->id)
        ->set('editContractEndDate', '2030-01-01')
        ->call('saveContractEndDate');

    $primary->refresh();
    expect($primary->contract_end_date->format('Y-m-d'))->toBe('2030-01-01');
    expect($primary->ended_at)->toBeNull();
    expect($primary->is_primary_owner)->toBeTrue();
});

test('the relationships table hides ended relationships by default and reveals them via the toggle', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'type' => 'tenant']);
    $endedUnit = Unit::factory()->create();
    PersonUnitRelationship::factory()->ended()->create(['person_id' => $person->id, 'unit_id' => $endedUnit->id, 'type' => 'tenant']);

    $component = Volt::test('pages.people.show', ['person' => $person]);

    expect($component->html())->not->toContain($endedUnit->unitCode());

    $component->set('showEndedRelationships', true);

    expect($component->html())->toContain($endedUnit->unitCode());
});

test('the relationships table sorts the primary-owner unit first, then by unit code', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();

    $unitZ = Unit::factory()->create(['building_code' => 'Z', 'floor_code' => '01', 'unit_number' => '01']);
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unitZ->id, 'type' => 'tenant']);

    $unitA = Unit::factory()->create(['building_code' => 'A', 'floor_code' => '01', 'unit_number' => '01']);
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unitA->id, 'type' => 'tenant']);

    $primaryUnit = Unit::factory()->create(['building_code' => 'M', 'floor_code' => '01', 'unit_number' => '01']);
    PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $person->id, 'unit_id' => $primaryUnit->id]);

    Volt::test('pages.people.show', ['person' => $person])
        ->assertSeeInOrder([$primaryUnit->unitCode(), $unitA->unitCode(), $unitZ->unitCode()]);
});

test('ending a relationship with no active card closes it immediately', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'type' => 'tenant']);

    Volt::test('pages.people.show', ['person' => $person])
        ->call('stageCloseRelationship', $relationship->id);

    expect($relationship->fresh()->ended_at)->not->toBeNull();
});

test('ending a relationship with an active card stages a preview instead of closing immediately', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();
    $unit = Unit::factory()->create();
    $relationship = PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'tenant']);
    $card = IdCard::factory()->create(['person_id' => $person->id, 'unit_id' => $unit->id, 'type' => 'tenant', 'status' => 'active']);

    $component = Volt::test('pages.people.show', ['person' => $person])
        ->call('stageCloseRelationship', $relationship->id);

    expect($relationship->fresh()->ended_at)->toBeNull();
    expect($card->fresh()->status)->toBe('active');
    expect($component->get('closePreviewCards'))->toHaveCount(1);

    $component->call('confirmCloseRelationship');

    expect($relationship->fresh()->ended_at)->not->toBeNull();
    expect($card->fresh()->status)->toBe('expired');
});

test('ending a relationship offers a replacement card when the person is still entitled elsewhere', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();

    $unitToClose = Unit::factory()->create();
    $closingRelationship = PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $unitToClose->id, 'type' => 'tenant']);

    $otherUnit = Unit::factory()->create();
    PersonUnitRelationship::factory()->create(['person_id' => $person->id, 'unit_id' => $otherUnit->id, 'type' => 'tenant', 'start_date' => '2026-01-01']);

    $component = Volt::test('pages.people.show', ['person' => $person])
        ->call('stageCloseRelationship', $closingRelationship->id);

    expect($component->get('reissueOffered'))->toBeTrue();

    $component->call('issueOfferedReplacement');

    $newCard = IdCard::where('person_id', $person->id)->where('status', 'active')->first();
    expect($newCard)->not->toBeNull();
    expect($newCard->unit_id)->toBe($otherUnit->id);
    expect($component->get('reissueOffered'))->toBeFalse();
});

test('a primary-owner relationship has no End action', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $person->id]);

    $html = Volt::test('pages.people.show', ['person' => $person])->html();

    expect($html)->not->toMatch('/stageCloseRelationship\('.$relationship->id.'\)/');
});

test('ending the primary-owner relationship directly is refused', function () {
    // Defense in depth: even if the button were somehow clicked (it isn't
    // rendered for a primary-owner row), the underlying service still
    // refuses, matching the unit show page's identical guard.
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->primaryOwner()->create(['person_id' => $person->id]);

    Volt::test('pages.people.show', ['person' => $person])
        ->call('stageCloseRelationship', $relationship->id);

    expect($relationship->fresh()->ended_at)->toBeNull();
});
