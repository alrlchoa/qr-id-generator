<?php

use App\Models\Person;
use App\Models\User;
use Livewire\Volt\Volt;

test('a Superadmin can delete a person with no dependents from the show page', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    $person = Person::factory()->create();

    Volt::test('pages.people.show', ['person' => $person])->call('delete');

    expect($person->fresh()->deleted_at)->not->toBeNull();
});

test('an Admin cannot delete a person', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();

    Volt::test('pages.people.show', ['person' => $person])
        ->call('delete')
        ->assertForbidden();
});
