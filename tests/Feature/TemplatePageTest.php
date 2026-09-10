<?php

use App\Models\IdCard;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateManager;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    Storage::fake('local');
    bootstrapSystem();
});

test('an Admin cannot reach the templates index', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('templates.index'))->assertForbidden();
});

test('a Superadmin can create a template with the chosen orientation', function () {
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.templates.create')
        ->set('name', 'Owner card v1')
        ->set('id_type', 'owner')
        ->set('orientation', 'portrait')
        ->call('create')
        ->assertRedirect();

    $template = Template::where('name', 'Owner card v1')->firstOrFail();
    expect($template->width_px)->toBe(638)->and($template->height_px)->toBe(1011);
});

test('an Admin cannot reach the create-template page at all', function () {
    // mount() itself calls authorize('create'), same as templates.index — an
    // Admin never even gets far enough to submit the form.
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('templates.create'))->assertForbidden();
});

test('uploading front artwork through the show page stores it', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $template = app(TemplateManager::class)->createTemplate($actor, 'owner', 'x', 'landscape');

    Volt::test('pages.templates.show', ['template' => $template])
        ->set('frontOverlay', transparentPng(1011, 638))
        ->call('uploadFront')
        ->assertHasNoErrors();

    expect($template->fresh()->overlay_path_front)->not->toBeNull();
});

test('uploading artwork with the wrong dimensions shows an error, not a 500', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $template = app(TemplateManager::class)->createTemplate($actor, 'owner', 'x', 'landscape');

    Volt::test('pages.templates.show', ['template' => $template])
        ->set('frontOverlay', transparentPng(400, 400))
        ->call('uploadFront')
        ->assertHasErrors('frontOverlay');
});

test('saving field positions through the show page persists them', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $template = app(TemplateManager::class)->createTemplate($actor, 'owner', 'x', 'landscape');
    $positions = validPositionsFor($template);

    Volt::test('pages.templates.show', ['template' => $template])
        ->call('savePositions', $positions)
        ->assertHasNoErrors();

    expect($template->fresh()->field_positions_front)->not->toBeNull();
});

test('saving positions that obscure a non-QR field offers a confirm-and-retry rather than silently failing', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $manager = app(TemplateManager::class);
    $template = $manager->createTemplate($actor, 'owner', 'x', 'landscape');
    $positions = validPositionsFor($template);
    $manager->uploadFrontOverlay($actor, $template, pngWithOpaqueBox(1011, 638, $positions['photo']));

    $component = Volt::test('pages.templates.show', ['template' => $template]);
    $component->assertDontSee('Artwork covers a field');

    $component->call('savePositions', $positions);

    expect($component->get('pendingConfirm'))->toBeTrue();
    expect($template->fresh()->field_positions_front)->toBeNull();
    // Real markup a click would actually see, not just the property —
    // see IdCardPageTest's identical note on why this matters.
    $component->assertSee('Artwork covers a field');

    $component->call('confirmSaveDespiteWarning');

    expect($template->fresh()->field_positions_front)->not->toBeNull();
});

test('activating a template deactivates the previously active one for the same id_type', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $manager = app(TemplateManager::class);
    $first = completeTemplate($manager, $actor, 'owner');
    $manager->activate($actor, $first);
    $second = completeTemplate($manager, $actor, 'owner');

    Volt::test('pages.templates.show', ['template' => $second])
        ->call('activate')
        ->assertHasNoErrors();

    expect($first->fresh()->is_active)->toBeFalse();
    expect($second->fresh()->is_active)->toBeTrue();
});

test('activating an incomplete template shows an error rather than a 500', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $template = app(TemplateManager::class)->createTemplate($actor, 'owner', 'x', 'landscape');

    Volt::test('pages.templates.show', ['template' => $template])
        ->call('activate')
        ->assertHasErrors('activate');

    expect($template->fresh()->is_active)->toBeFalse();
});

test('deleting a template with issued cards is refused', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $manager = app(TemplateManager::class);
    $template = completeTemplate($manager, $actor, 'owner');
    IdCard::factory()->create(['template_id' => $template->id]);

    Volt::test('pages.templates.show', ['template' => $template])
        ->call('delete')
        ->assertHasErrors('delete');

    expect(Template::find($template->id))->not->toBeNull();
});
