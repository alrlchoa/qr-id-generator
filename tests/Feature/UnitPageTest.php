<?php

use App\Models\IdCard;
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

    // No active card on this relationship, so staging closes it immediately
    // — there's no card consequence worth a confirmation modal for.
    $component->call('stageCloseRelationship', $relationship->id);

    expect($relationship->fresh()->ended_at)->not->toBeNull();
});

test('closing a relationship with an active card stages a preview instead of closing immediately', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $tenant = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'person_id' => $tenant->id, 'type' => 'tenant']);
    $card = IdCard::factory()->create(['unit_id' => $unit->id, 'person_id' => $tenant->id, 'type' => 'tenant', 'status' => 'active']);

    $component = Volt::test('pages.units.show', ['unit' => $unit])
        ->call('stageCloseRelationship', $relationship->id);

    expect($relationship->fresh()->ended_at)->toBeNull();
    expect($card->fresh()->status)->toBe('active');
    expect($component->get('closePreviewCards'))->toHaveCount(1);
    expect($component->get('closingRelationshipId'))->toBe($relationship->id);

    $component->call('confirmCloseRelationship');

    expect($relationship->fresh()->ended_at)->not->toBeNull();
    expect($card->fresh()->status)->toBe('expired');
});

test('closing a relationship offers a replacement card when the person is still entitled elsewhere', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $tenant = Person::factory()->create();

    $unitToClose = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unitToClose->id]);
    $closingRelationship = PersonUnitRelationship::factory()->create(['unit_id' => $unitToClose->id, 'person_id' => $tenant->id, 'type' => 'tenant']);

    $otherUnit = Unit::factory()->create();
    PersonUnitRelationship::factory()->create(['unit_id' => $otherUnit->id, 'person_id' => $tenant->id, 'type' => 'tenant', 'start_date' => '2026-01-01']);

    $component = Volt::test('pages.units.show', ['unit' => $unitToClose])
        ->call('stageCloseRelationship', $closingRelationship->id);

    expect($component->get('reissueOfferPersonId'))->toBe($tenant->id);

    $component->call('issueOfferedReplacement');

    $newCard = IdCard::where('person_id', $tenant->id)->where('status', 'active')->first();
    expect($newCard)->not->toBeNull();
    expect($newCard->unit_id)->toBe($otherUnit->id);
    expect($component->get('reissueOfferPersonId'))->toBeNull();
});

test('closing a relationship offers no replacement when the person holds no other relationship', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $tenant = Person::factory()->create();
    $relationship = PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'person_id' => $tenant->id, 'type' => 'tenant']);

    $component = Volt::test('pages.units.show', ['unit' => $unit])
        ->call('stageCloseRelationship', $relationship->id);

    expect($component->get('reissueOfferPersonId'))->toBeNull();
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

test('the units index sorts by primary owner name', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unitA = Unit::factory()->create(['floor_code' => '01', 'unit_number' => '01']);
    $ownerA = Person::factory()->create(['first_name' => 'Zed', 'middle_name' => null, 'last_name' => 'Zephyr']);
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unitA->id, 'person_id' => $ownerA->id]);

    $unitB = Unit::factory()->create(['floor_code' => '01', 'unit_number' => '02']);
    $ownerB = Person::factory()->create(['first_name' => 'Amy', 'middle_name' => null, 'last_name' => 'Alpha']);
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unitB->id, 'person_id' => $ownerB->id]);

    Volt::test('pages.units.index')
        ->call('sortBy', 'primary_owner')
        ->assertSeeInOrder(['Alpha, Amy', 'Zephyr, Zed']);
});

test('the create-unit owner picker only lists contactable-tier people, formatted as "id - name"', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $eligible = Person::factory()->create(['first_name' => 'Amy', 'middle_name' => null, 'last_name' => 'Alpha', 'mobile_number' => '09171234567', 'email' => 'amy@example.com']);
    $ineligible = Person::factory()->minimal()->create();
    $company = Person::factory()->company()->create(['legal_name' => 'Acme Holdings Inc.', 'mobile_number' => '09171234567', 'email' => 'rep@acme.example']);

    $availableOwners = Volt::test('pages.units.create')->get('availableOwners');
    $byIdNumber = collect($availableOwners)->keyBy('id_number');

    expect($byIdNumber->has($eligible->user_id_number))->toBeTrue();
    expect($byIdNumber[$eligible->user_id_number]['label'])->toBe("{$eligible->user_id_number} - Alpha, Amy");

    expect($byIdNumber->has($ineligible->user_id_number))->toBeFalse();

    expect($byIdNumber->has($company->user_id_number))->toBeTrue();
    expect($byIdNumber[$company->user_id_number]['label'])->toBe("{$company->user_id_number} - Acme Holdings Inc.");
});
