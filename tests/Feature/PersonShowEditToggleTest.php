<?php

use App\Models\Person;
use App\Models\User;
use Livewire\Volt\Volt;

test('the person block opens read-only, formatted, not the editable form', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'mobile_number' => '09171234567']);

    $component = Volt::test('pages.people.show', ['person' => $person]);

    $component->assertSee($person->displayName())
        ->assertSee('09171234567')
        ->assertDontSeeHtml('wire:model="first_name"')
        ->assertSee('Edit');
});

test('clicking Edit reveals the editable form', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();

    Volt::test('pages.people.show', ['person' => $person])
        ->call('startEditing')
        ->assertSeeHtml('wire:model="first_name"');
});

test('saving returns to the read-only view', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();

    Volt::test('pages.people.show', ['person' => $person])
        ->call('startEditing')
        ->set('notes', 'A note.')
        ->call('save')
        ->assertDontSeeHtml('wire:model="first_name"')
        ->assertSee('A note.');
});

test('cancelling discards unsaved changes and returns to the read-only view', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create(['notes' => 'Original.']);

    Volt::test('pages.people.show', ['person' => $person])
        ->call('startEditing')
        ->set('notes', 'Unsaved change.')
        ->call('resetForm')
        ->assertDontSeeHtml('wire:model="first_name"')
        ->assertSee('Original.')
        ->assertDontSee('Unsaved change.');

    expect($person->refresh()->notes)->toBe('Original.');
});

test('a validation failure while editing keeps the form open with the error visible', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $person = Person::factory()->create();

    Volt::test('pages.people.show', ['person' => $person])
        ->call('startEditing')
        ->set('first_name', '')
        ->call('save')
        ->assertHasErrors('first_name')
        ->assertSeeHtml('wire:model="first_name"');
});
