<?php

use App\Livewire\Pages\Dev\ComponentsPreview;
use App\Models\User;
use Livewire\Livewire;

/**
 * Exercised through the component-preview page (Livewire::test() mounts
 * the class directly, independent of whether its route is registered —
 * the route only exists under APP_ENV=local; see ComponentPreviewTest for
 * that half). The behaviour under test is HasSortableColumns itself, not
 * anything specific to the preview page's sample data.
 */
test('sorting by an allowed column sets it and defaults to ascending', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Livewire::test(ComponentsPreview::class)
        ->call('sortBy', 'control_number')
        ->assertSet('sortColumn', 'control_number')
        ->assertSet('sortDirection', 'asc');
});

test('sorting by the same column again toggles direction', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Livewire::test(ComponentsPreview::class)
        ->call('sortBy', 'control_number')
        ->call('sortBy', 'control_number')
        ->assertSet('sortDirection', 'desc')
        ->call('sortBy', 'control_number')
        ->assertSet('sortDirection', 'asc');
});

test('sorting by a different column resets direction to ascending', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Livewire::test(ComponentsPreview::class)
        ->call('sortBy', 'control_number')
        ->call('sortBy', 'control_number') // now desc
        ->call('sortBy', 'unit')
        ->assertSet('sortColumn', 'unit')
        ->assertSet('sortDirection', 'asc');
});

test('a column outside the allowlist is silently ignored', function () {
    // The actual security property (Phase 13 trap, CLAUDE.md): a value that
    // did not come from the allowlist must never reach orderBy(). Proven
    // here by state simply not changing, not by an exception — a crafted
    // wire:click payload calling sortBy() with an arbitrary string is
    // exactly the shape of input this has to survive silently.
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Livewire::test(ComponentsPreview::class)
        ->call('sortBy', 'control_number')
        ->call('sortBy', 'id; DROP TABLE users; --')
        ->assertSet('sortColumn', 'control_number')
        ->assertSet('sortDirection', 'asc');
});
