<?php

use App\Models\Person;
use App\Models\User;
use Livewire\Volt\Volt;

test('the delete-person button is not nested inside another button', function () {
    // Same shape as the Units show page's identical bug: <button
    // wire:click="delete" ...><x-danger-button>Delete</x-danger-button></button>
    // is a <button> nested inside a <button>, invalid per the HTML5
    // content model. Browsers implicitly close the outer one the moment
    // they hit the inner one, so the visible "Delete" button ends up with
    // no click handler — wire:click stayed on the outer, now-empty
    // button. Every existing test here calls ->call('delete') directly,
    // bypassing the button entirely, which is why this went uncaught.
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    $person = Person::factory()->create();

    $html = Volt::test('pages.people.show', ['person' => $person])->html();

    expect(substr_count($html, '>Delete<'))->toBe(1);
    expect($html)->toMatch('/<button[^>]*wire:click="delete"[^>]*>\s*Delete\s*<\/button>/');
});

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
