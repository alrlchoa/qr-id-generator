<?php

use App\Models\Font;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    bootstrapSystem();
});

test('a font file is unreachable without a session', function () {
    $font = Font::factory()->create();

    $this->get(route('fonts.file', $font))->assertRedirect(route('login'));
});

test('an Admin cannot fetch a font file — Superadmin-only, same as the Fonts screen', function () {
    $font = Font::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('fonts.file', $font))
        ->assertForbidden();
});

test('a Superadmin can fetch an uploaded font\'s file', function () {
    Storage::disk('local')->put('card-fonts/real.ttf', 'fake-ttf-bytes');
    $font = Font::factory()->create(['storage_path' => 'card-fonts/real.ttf']);

    $this->actingAs(User::factory()->superadmin()->create())
        ->get(route('fonts.file', $font))
        ->assertOk();
});

test('a font whose file is missing from disk 404s instead of erroring', function () {
    $font = Font::factory()->create(['storage_path' => 'card-fonts/gone.ttf']);

    $this->actingAs(User::factory()->superadmin()->create())
        ->get(route('fonts.file', $font))
        ->assertNotFound();
});
