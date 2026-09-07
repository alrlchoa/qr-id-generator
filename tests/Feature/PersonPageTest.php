<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\User;
use App\Services\UserAccountManager;
use Livewire\Volt\Volt;

test('a Reader cannot view the people index', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->reader()->create());

    $this->get('/people')->assertForbidden();
});

test('an Admin can view the people index', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->admin()->create());

    $this->get('/people')->assertOk()->assertSeeVolt('pages.people.index');
});

test('creating a natural person with only first and last name reads back unchanged through index and detail', function () {
    bootstrapSystem();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Volt::test('pages.people.create')
        ->set('entity_type', 'natural')
        ->set('first_name', 'Ada')
        ->set('last_name', 'Lovelace')
        ->call('create')
        ->assertHasNoErrors();

    $person = Person::where('first_name', 'Ada')->where('last_name', 'Lovelace')->firstOrFail();

    expect($person->entity_type)->toBe('natural')
        ->and($person->legal_name)->toBeNull()
        ->and($person->photo_path)->toBeNull()
        ->and($person->mobile_number)->toBeNull()
        ->and($person->email)->toBeNull()
        ->and($person->isCardable())->toBeFalse();

    $this->get('/people')->assertOk()->assertSee('Lovelace, Ada');

    $this->get(route('people.show', $person))->assertOk()->assertSee($person->user_id_number);

    $log = AuditLog::where('subject_type', $person->getMorphClass())->where('subject_id', $person->id)->where('action', 'person_created')->first();
    expect($log)->not->toBeNull();
});

test('creating a company with only a legal name is listed and searchable via display_name alongside natural persons', function () {
    bootstrapSystem();

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Person::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

    Volt::test('pages.people.create')
        ->set('entity_type', 'company')
        ->set('legal_name', 'Acme Holdings Inc.')
        ->call('create')
        ->assertHasNoErrors();

    $company = Person::where('legal_name', 'Acme Holdings Inc.')->firstOrFail();

    expect($company->entity_type)->toBe('company')
        ->and($company->first_name)->toBeNull()
        ->and($company->displayName())->toBe('Acme Holdings Inc.')
        ->and($company->isCardable())->toBeFalse();

    Volt::test('pages.people.index')
        ->set('search', 'Acme')
        ->assertSee('Acme Holdings Inc.')
        ->assertDontSee('Hopper, Grace');
});

test('the people index sorts by the sortable-columns allowlist only', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Person::factory()->create(['first_name' => 'Zed', 'last_name' => 'Zephyr', 'user_id_number' => '00000009']);
    Person::factory()->create(['first_name' => 'Ann', 'last_name' => 'Alpha', 'user_id_number' => '00000001']);

    Volt::test('pages.people.index')
        ->call('sortBy', 'user_id_number')
        ->assertSet('sortColumn', 'user_id_number')
        ->call('sortBy', 'id; DROP TABLE people; --')
        ->assertSet('sortColumn', 'user_id_number');
});

test('the "active relationship, no photo" filter finds only photo-less people with an active relationship', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $target = Person::factory()->minimal()->create(['first_name' => 'Needs', 'middle_name' => null, 'last_name' => 'Photo']);
    PersonUnitRelationship::factory()->create(['person_id' => $target->id, 'ended_at' => null]);

    $hasPhoto = Person::factory()->create(['first_name' => 'Has', 'middle_name' => null, 'last_name' => 'Photo']);
    PersonUnitRelationship::factory()->create(['person_id' => $hasPhoto->id, 'ended_at' => null]);

    $noRelationship = Person::factory()->minimal()->create(['first_name' => 'No', 'middle_name' => null, 'last_name' => 'Relationship']);

    Volt::test('pages.people.index')
        ->set('needsPhoto', true)
        ->assertSee('Photo, Needs')
        ->assertDontSee('Photo, Has')
        ->assertDontSee('Relationship, No');
});

test('the people index sorts by name: naturals by last then first, companies grouped after', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Person::factory()->create(['first_name' => 'Bob', 'middle_name' => null, 'last_name' => 'Zephyr']);
    Person::factory()->create(['first_name' => 'Amy', 'middle_name' => null, 'last_name' => 'Alpha']);
    Person::factory()->company()->create(['legal_name' => 'Aardvark Co.']);

    Volt::test('pages.people.index')
        ->call('sortBy', 'name')
        ->assertSeeInOrder(['Alpha, Amy', 'Zephyr, Bob', 'Aardvark Co.']);
});

test('the people index sorts by kind', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Person::factory()->create(['first_name' => 'Bob', 'last_name' => 'Zephyr']);
    Person::factory()->company()->create(['legal_name' => 'Aardvark Co.']);

    Volt::test('pages.people.index')
        ->call('sortBy', 'kind')
        ->assertSeeInOrder(['Aardvark Co.', 'Zephyr, Bob']);
});

test('a company cannot be linked to a user account', function () {
    bootstrapSystem();

    $superadmin = User::factory()->superadmin()->create();
    $company = Person::factory()->company()->create();

    expect(fn () => app(UserAccountManager::class)->createAccount(
        $superadmin,
        'acme.rep',
        'Acme Representative',
        Role::Reader,
        $company->id,
    ))->toThrow(InvalidArgumentException::class);
});
