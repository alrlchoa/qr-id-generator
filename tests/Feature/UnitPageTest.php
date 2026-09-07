<?php

use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use Livewire\Volt\Volt;

test('a Reader cannot view the units index', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->reader()->create());

    $this->get('/units')->assertForbidden();
});

test('an Admin can view the units index and create page', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/units')->assertOk()->assertSeeVolt('pages.units.index');
    $this->get('/units/create')->assertOk()->assertSeeVolt('pages.units.create');
});

test('creating a unit through the form opens its primary-owner relationship', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $owner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'owner@example.com']);

    Volt::test('pages.units.create')
        ->set('building_code', 'A')
        ->set('floor_code', '01')
        ->set('unit_number', '06')
        ->set('start_date', '2026-01-01')
        ->set('ownerMode', 'existing')
        ->set('existing_owner_id_number', $owner->user_id_number)
        ->call('create')
        ->assertHasNoErrors();

    $unit = Unit::where('floor_code', '01')->where('unit_number', '06')->firstOrFail();
    expect($unit->primaryOwnerPersonId())->toBe($owner->id);
});

test('creating a unit with an unknown owner ID number fails cleanly', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('pages.units.create')
        ->set('floor_code', '02')
        ->set('unit_number', '01')
        ->set('start_date', '2026-01-01')
        ->set('ownerMode', 'existing')
        ->set('existing_owner_id_number', '99999999')
        ->call('create')
        ->assertHasErrors('existing_owner_id_number');

    expect(Unit::where('floor_code', '02')->where('unit_number', '01')->exists())->toBeFalse();
});

test('the unit show page opens and closes an ordinary relationship', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $tenant = Person::factory()->create();

    $component = Volt::test('pages.units.show', ['unit' => $unit])
        ->set('open_person_id_number', $tenant->user_id_number)
        ->set('open_type', 'tenant')
        ->set('open_start_date', '2026-01-01')
        ->call('openRelationship')
        ->assertHasNoErrors();

    $relationship = PersonUnitRelationship::where('unit_id', $unit->id)->where('person_id', $tenant->id)->firstOrFail();
    expect($relationship->ended_at)->toBeNull();

    $component->call('closeRelationship', $relationship->id);

    expect($relationship->fresh()->ended_at)->not->toBeNull();
});

test('the unit show page promotes a co-owner to primary', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $coOwner = PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'type' => 'owner', 'is_primary_owner' => false]);

    Volt::test('pages.units.show', ['unit' => $unit])
        ->set('promote_relationship_id', (string) $coOwner->id)
        ->call('promote');

    expect($unit->fresh()->primaryOwnerPersonId())->toBe($coOwner->person_id);
});

test('the unit show page transfers primary ownership to an existing person', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $incoming = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'incoming@example.com']);

    Volt::test('pages.units.show', ['unit' => $unit])
        ->set('transferMode', 'existing')
        ->set('transfer_existing_id_number', $incoming->user_id_number)
        ->set('transfer_start_date', '2026-02-01')
        ->call('transfer');

    expect($unit->fresh()->primaryOwnerPersonId())->toBe($incoming->id);
});

test('a Superadmin can delete a unit left with only its primary-owner relationship, then restore it', function () {
    bootstrapSystem();
    $superadmin = User::factory()->superadmin()->create();
    $this->actingAs($superadmin);

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);

    Volt::test('pages.units.show', ['unit' => $unit])->call('delete');

    expect(Unit::find($unit->id))->toBeNull();
    expect(Unit::withTrashed()->findOrFail($unit->id)->deleted_at)->not->toBeNull();

    $newOwner = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'restored@example.com']);

    Volt::test('pages.units.show', ['unit' => $unit])
        ->set('restore_person_id_number', $newOwner->user_id_number)
        ->set('restore_start_date', '2026-03-01')
        ->call('restore')
        ->assertHasNoErrors();

    $restored = Unit::findOrFail($unit->id);
    expect($restored->deleted_at)->toBeNull();
    expect($restored->primaryOwnerPersonId())->toBe($newOwner->id);
});

test('an Admin cannot delete a unit', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);

    Volt::test('pages.units.show', ['unit' => $unit])
        ->call('delete')
        ->assertForbidden();
});
