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

test('a failed save\'s error message disappears on a later successful save, replaced by a success message', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $template = app(TemplateManager::class)->createTemplate($actor, 'owner', 'x', 'landscape');
    // The field placement section — including where its error would
    // render — only appears once front artwork exists, same as the real
    // UI: there's no way to reach the Save Positions button before this.
    app(TemplateManager::class)->uploadFrontOverlay($actor, $template, transparentPng(1011, 638));
    $goodPositions = validPositionsFor($template);
    $overlapping = $goodPositions;
    $overlapping['name'] = ['x' => 20, 'y' => 20, 'width' => 100, 'height' => 100]; // overlaps 'photo'

    $component = Volt::test('pages.templates.show', ['template' => $template]);

    $component->call('savePositions', $overlapping)->assertHasErrors('positions');

    // Livewire only auto-clears a field's error on a later validate() call
    // for that same key succeeding — savePositions() never calls
    // validate() at all, so without an explicit reset this message would
    // otherwise still be here on the very next render, including after a
    // successful save.
    $component->call('savePositions', $goodPositions)->assertHasNoErrors();

    expect($template->fresh()->field_positions_front)->not->toBeNull();
});

test('a failed save is replaced by a new, different error on a second failed attempt — not both shown at once', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $template = app(TemplateManager::class)->createTemplate($actor, 'owner', 'x', 'landscape');
    app(TemplateManager::class)->uploadFrontOverlay($actor, $template, transparentPng(1011, 638));
    $positions = validPositionsFor($template);
    $overlapping = $positions;
    $overlapping['name'] = ['x' => 20, 'y' => 20, 'width' => 100, 'height' => 100];
    $outOfBounds = $positions;
    $outOfBounds['photo']['x'] = $template->width_px;

    $component = Volt::test('pages.templates.show', ['template' => $template]);

    $component->call('savePositions', $overlapping)
        ->assertHasErrors('positions')
        ->assertSee('overlap');

    $component->call('savePositions', $outOfBounds)
        ->assertHasErrors('positions')
        ->assertSee('outside the')
        ->assertDontSee('overlap');
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

test('the incomplete-template error clears once activation succeeds on a later attempt', function () {
    $this->actingAs($actor = User::factory()->superadmin()->create());
    $manager = app(TemplateManager::class);
    $template = $manager->createTemplate($actor, 'owner', 'x', 'landscape');

    $component = Volt::test('pages.templates.show', ['template' => $template]);
    $component->call('activate')->assertHasErrors('activate');

    $manager->uploadFrontOverlay($actor, $template, transparentPng(1011, 638));
    $manager->uploadBackOverlay($actor, $template, transparentPng(1011, 638));
    $manager->saveFieldPositions($actor, $template, validPositionsFor($template));

    $component->call('activate')->assertHasNoErrors();

    expect($template->fresh()->is_active)->toBeTrue();
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
