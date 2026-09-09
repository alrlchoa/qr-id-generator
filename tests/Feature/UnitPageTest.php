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

test('the open-relationship and promote buttons are real submit buttons, not inert type="button"s', function () {
    // Regression guard: <x-secondary-button> defaults to type="button"
    // (resources/views/components/secondary-button.blade.php) unless the
    // caller overrides it. Both of these buttons sat inside a real
    // `wire:submit` form with no override since Phase 7 — clicking either
    // one did nothing at all, and no test caught it because every other
    // test here calls the Livewire action directly (->call('openRelationship'),
    // ->call('promote')), bypassing the button entirely. Asserting the
    // rendered attribute is what actually catches this class of bug.
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'type' => 'owner', 'is_primary_owner' => false]);

    $html = Volt::test('pages.units.show', ['unit' => $unit])->html();

    expect($html)->toContain('type="submit"')
        ->and($html)->toMatch('/<button type="submit"[^>]*>\s*Open relationship\s*<\/button>/')
        ->and($html)->toMatch('/<button type="submit"[^>]*>\s*Promote\s*<\/button>/');
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

test('the delete-unit button is not nested inside another button', function () {
    // Regression guard: the button was previously `<button
    // wire:click="delete" ...><x-danger-button>Delete unit</x-danger-button></button>`
    // — a <button> inside a <button>, invalid per the HTML5 content model.
    // Browsers implicitly close the outer button the moment they hit the
    // inner one, so the visible "Delete unit" button (the inner one) ends
    // up with no click handler at all: wire:click and wire:confirm stayed
    // on the outer, now-empty button. Clicking the button a user actually
    // sees did nothing. No existing test caught it, the same way as the
    // Open Relationship/Promote bug: every test called ->call('delete')
    // directly. Asserting there's exactly one <button>...Delete unit</button>
    // pair, with wire:click on it, is what actually catches this shape.
    bootstrapSystem();
    $superadmin = User::factory()->superadmin()->create();
    $this->actingAs($superadmin);

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);

    $html = Volt::test('pages.units.show', ['unit' => $unit])->html();

    // Exactly one <button>...Delete unit</button> pair (not the section
    // heading, which also contains this text), and it carries wire:click.
    expect(substr_count($html, '>Delete unit<'))->toBe(1);
    expect($html)->toMatch('/<button[^>]*wire:click="delete"[^>]*>\s*Delete unit\s*<\/button>/');
});

test('deleting a unit through the page renders the refusal message when it is blocked', function () {
    bootstrapSystem();
    $superadmin = User::factory()->superadmin()->create();
    $this->actingAs($superadmin);

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'type' => 'tenant']);

    $html = Volt::test('pages.units.show', ['unit' => $unit])->call('delete')->html();

    expect($unit->fresh()->deleted_at)->toBeNull();
    expect($html)->toContain('still has active relationships or cards');
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

test('the relationships table hides ended relationships by default and reveals them via the toggle', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $endedTenant = Person::factory()->create(['first_name' => 'Gone', 'middle_name' => null, 'last_name' => 'Already']);
    PersonUnitRelationship::factory()->ended()->create(['unit_id' => $unit->id, 'person_id' => $endedTenant->id, 'type' => 'tenant']);

    // Matched as an exact table cell, not a bare substring: the
    // open-relationship picker's Alpine `x-data` embeds every person
    // (tier notwithstanding — CLAUDE.md's own "no relationship-opening
    // tier requirement") as JSON on this same page, so "Already, Gone"
    // legitimately appears there regardless of the toggle.
    $component = Volt::test('pages.units.show', ['unit' => $unit]);

    expect($component->html())->not->toContain('>Already, Gone</td>');

    $component->set('showEndedRelationships', true);

    expect($component->html())->toContain('>Already, Gone</td>');
});

test('the relationships table sorts the primary owner first, then everyone else by name', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    $primary = Person::factory()->create(['first_name' => 'Mid', 'middle_name' => null, 'last_name' => 'Middleton']);
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id, 'person_id' => $primary->id]);

    $zed = Person::factory()->create(['first_name' => 'Zed', 'middle_name' => null, 'last_name' => 'Zephyr']);
    PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'person_id' => $zed->id, 'type' => 'tenant']);

    $amy = Person::factory()->create(['first_name' => 'Amy', 'middle_name' => null, 'last_name' => 'Alpha']);
    PersonUnitRelationship::factory()->create(['unit_id' => $unit->id, 'person_id' => $amy->id, 'type' => 'tenant']);

    Volt::test('pages.units.show', ['unit' => $unit])
        ->assertSeeInOrder(['Middleton, Mid', 'Alpha, Amy', 'Zephyr, Zed']);
});

test('the open-relationship picker lists every natural person, tier notwithstanding, formatted as "id - name"', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);

    $minimal = Person::factory()->minimal()->create(['first_name' => 'Bare', 'middle_name' => null, 'last_name' => 'Bones']);

    $options = Volt::test('pages.units.show', ['unit' => $unit])->get('openRelationshipOptions');
    $byIdNumber = collect($options)->keyBy('id_number');

    expect($byIdNumber->has($minimal->user_id_number))->toBeTrue();
    expect($byIdNumber[$minimal->user_id_number]['label'])->toBe("{$minimal->user_id_number} - Bones, Bare");
});

test('the open-relationship picker excludes companies — they can only ever be a primary owner', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);

    $company = Person::factory()->company()->create();

    $options = Volt::test('pages.units.show', ['unit' => $unit])->get('openRelationshipOptions');

    expect(collect($options)->pluck('id_number'))->not->toContain($company->user_id_number);
});

test('the transfer-ownership picker only lists contactable-tier people', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);

    $eligible = Person::factory()->create(['mobile_number' => '09171234567', 'email' => 'eligible@example.com']);
    $ineligible = Person::factory()->minimal()->create();

    $options = Volt::test('pages.units.show', ['unit' => $unit])->get('transferOwnerOptions');
    $byIdNumber = collect($options)->keyBy('id_number');

    expect($byIdNumber->has($eligible->user_id_number))->toBeTrue();
    expect($byIdNumber->has($ineligible->user_id_number))->toBeFalse();
});

test('opening a relationship via the picker-populated field still works end to end', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $tenant = Person::factory()->create();

    Volt::test('pages.units.show', ['unit' => $unit])
        ->set('open_person_id_number', $tenant->user_id_number)
        ->set('open_type', 'tenant')
        ->set('open_start_date', '2026-01-01')
        ->call('openRelationship')
        ->assertHasNoErrors();

    expect(PersonUnitRelationship::where('unit_id', $unit->id)->where('person_id', $tenant->id)->exists())->toBeTrue();
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
