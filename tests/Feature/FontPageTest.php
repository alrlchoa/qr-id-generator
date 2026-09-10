<?php

use App\Models\Font;
use App\Models\User;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    Storage::fake('local');
    bootstrapSystem();
});

test('an Admin cannot reach the fonts screen', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('fonts.index'))->assertForbidden();
});

test('a Superadmin sees an upload error, not a 500, for a non-font file', function () {
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.fonts.index')
        ->set('upload', File::createWithContent('not-a-font.ttf', 'plain text'))
        ->call('uploadFont')
        ->assertHasErrors('upload');

    expect(Font::count())->toBe(0);
});

test('activating a font through the screen deactivates the previous one', function () {
    $this->actingAs(User::factory()->superadmin()->create());
    $first = Font::factory()->active()->create();
    $second = Font::factory()->create();

    Volt::test('pages.fonts.index')
        ->call('activate', $second->id)
        ->assertHasNoErrors();

    expect($first->fresh()->is_active)->toBeFalse();
    expect($second->fresh()->is_active)->toBeTrue();
});

test('deleting the active font through the screen is refused with a visible error', function () {
    $this->actingAs(User::factory()->superadmin()->create());
    $font = Font::factory()->active()->create();

    Volt::test('pages.fonts.index')
        ->call('delete', $font->id)
        ->assertHasErrors('delete');

    expect(Font::find($font->id))->not->toBeNull();
});

test('deleting an inactive font through the screen removes it', function () {
    $this->actingAs(User::factory()->superadmin()->create());
    Storage::disk('local')->put('card-fonts/gone.ttf', 'bytes');
    $font = Font::factory()->create(['storage_path' => 'card-fonts/gone.ttf']);

    Volt::test('pages.fonts.index')
        ->call('delete', $font->id);

    expect(Font::find($font->id))->toBeNull();
});
