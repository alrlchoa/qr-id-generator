<?php

// Phase 14 gave <x-toast> and <x-confirm-dialog> a server-driven mode each.
// The pages exercise them end to end; these pin the component contract
// itself — what renders, what doesn't, and that a server-driven dialog never
// closes itself in the browser, which would hide a validation error raised
// inside it (the reason the card lifecycle dialog was once hand-built).

test('a server-driven toast renders its message, and nothing without one', function () {
    $this->blade('<x-toast :message="$m" />', ['m' => 'Relationship closed.'])
        ->assertSee('Relationship closed.')
        ->assertSee('role="status"', false);

    expect(trim((string) $this->blade('<x-toast :message="$m" />', ['m' => null])))->toBe('');
});

test('an error toast is announced as an alert', function () {
    $this->blade('<x-toast :message="$m" variant="error" />', ['m' => 'That person is not cardable.'])
        ->assertSee('That person is not cardable.')
        ->assertSee('role="alert"', false)
        ->assertSee('bg-red-50', false);
});

test('a server-driven confirm dialog renders only while open, and never closes itself', function () {
    $this->blade('<x-confirm-dialog :open="false" title="Revoke this card?" confirm-action="confirmStaged" cancel-action="cancelStaged">Body</x-confirm-dialog>')
        ->assertDontSee('Revoke this card?');

    $this->blade('<x-confirm-dialog :open="true" title="Revoke this card?" confirm-action="confirmStaged" cancel-action="cancelStaged" cancel-label="Go back">Body</x-confirm-dialog>')
        ->assertSee('Revoke this card?')
        ->assertSee('Body')
        ->assertSee('Go back')
        ->assertSee('wire:click="confirmStaged"', false)
        ->assertSee('wire:click="cancelStaged"', false)
        ->assertDontSee('close-modal', false);
});

test('an event-driven confirm dialog still closes itself in the browser', function () {
    $this->blade('<x-confirm-dialog name="close-relationship" title="Close this relationship?" confirm-action="confirmCloseRelationship">Body</x-confirm-dialog>')
        ->assertSee('Close this relationship?')
        ->assertSee("\$dispatch('close-modal', 'close-relationship')", false)
        ->assertSee('wire:click="confirmCloseRelationship"', false);
});
