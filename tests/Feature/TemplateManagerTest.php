<?php

use App\Exceptions\TemplateFieldObscuredException;
use App\Exceptions\TemplateOverlayObscuresQrException;
use App\Models\IdCard;
use App\Models\Template;
use App\Models\User;
use App\Services\TemplateManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// transparentPng(), pngWithOpaqueBox(), validPositionsFor(), and
// completeTemplate() are shared helpers in tests/Pest.php — CardRendererTest
// and any future template-editor UI test lean on the same fixtures.

beforeEach(function () {
    Storage::fake('local');
    $this->actor = User::factory()->admin()->create();
    $this->manager = app(TemplateManager::class);
});

test('createTemplate sets dimensions from the chosen orientation', function () {
    $landscape = $this->manager->createTemplate($this->actor, 'owner', 'Owner card', Template::ORIENTATION_LANDSCAPE);
    $portrait = $this->manager->createTemplate($this->actor, 'tenant', 'Tenant card', Template::ORIENTATION_PORTRAIT);

    expect($landscape->width_px)->toBe(1011)->and($landscape->height_px)->toBe(638);
    expect($landscape->orientation())->toBe('landscape');
    expect($portrait->width_px)->toBe(638)->and($portrait->height_px)->toBe(1011);
    expect($portrait->orientation())->toBe('portrait');
});

test('createTemplate refuses an invalid id_type', function () {
    expect(fn () => $this->manager->createTemplate($this->actor, 'guest', 'x', 'landscape'))
        ->toThrow(InvalidArgumentException::class);
});

test('uploadFrontOverlay refuses a JPEG', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');
    $jpeg = UploadedFile::fake()->image('front.jpg', 1011, 638);

    expect(fn () => $this->manager->uploadFrontOverlay($this->actor, $template, $jpeg))
        ->toThrow(InvalidArgumentException::class);
});

test('uploadFrontOverlay refuses artwork that does not match the template dimensions', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');
    $wrongSize = transparentPng(500, 500);

    expect(fn () => $this->manager->uploadFrontOverlay($this->actor, $template, $wrongSize))
        ->toThrow(InvalidArgumentException::class);

    expect($template->fresh()->overlay_path_front)->toBeNull();
});

test('uploadFrontOverlay stores a correctly-sized PNG', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');

    $this->manager->uploadFrontOverlay($this->actor, $template, transparentPng(1011, 638));

    expect($template->fresh()->overlay_path_front)->not->toBeNull();
    Storage::disk('local')->assertExists($template->fresh()->overlay_path_front);
});

test('uploadFrontOverlay deletes the previous file when replaced', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');
    $this->manager->uploadFrontOverlay($this->actor, $template, transparentPng(1011, 638));
    $firstPath = $template->fresh()->overlay_path_front;

    $this->manager->uploadFrontOverlay($this->actor, $template, transparentPng(1011, 638));

    Storage::disk('local')->assertMissing($firstPath);
});

test('saveFieldPositions refuses a position set missing a required field', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');

    expect(fn () => $this->manager->saveFieldPositions($this->actor, $template, [
        'photo' => ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100],
    ]))->toThrow(InvalidArgumentException::class);
});

test('saveFieldPositions refuses an unexpected field for the id_type', function () {
    $template = $this->manager->createTemplate($this->actor, 'employee', 'x', 'landscape');

    $positions = validPositionsFor($template);
    $positions['unit_number'] = ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10];

    expect(fn () => $this->manager->saveFieldPositions($this->actor, $template, $positions))
        ->toThrow(InvalidArgumentException::class);
});

test('saveFieldPositions refuses a box that falls outside the canvas', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');
    $positions = validPositionsFor($template);
    $positions['photo']['x'] = $template->width_px;

    expect(fn () => $this->manager->saveFieldPositions($this->actor, $template, $positions))
        ->toThrow(InvalidArgumentException::class);
});

test('saveFieldPositions accepts numeric strings and coerces them to ints', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');
    $positions = validPositionsFor($template);
    $positions['photo'] = ['x' => '10', 'y' => '10', 'width' => '100', 'height' => '100'];

    $this->manager->saveFieldPositions($this->actor, $template, $positions);

    expect($template->fresh()->field_positions_front['photo']['x'])->toBe(10)->toBeInt();
});

test('saveFieldPositions succeeds with no overlay uploaded yet', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');

    $this->manager->saveFieldPositions($this->actor, $template, validPositionsFor($template));

    expect($template->fresh()->field_positions_front)->not->toBeNull();
});

test('saveFieldPositions refuses outright when the overlay is opaque over the QR box', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');
    $positions = validPositionsFor($template);
    $this->manager->uploadFrontOverlay($this->actor, $template, pngWithOpaqueBox(1011, 638, $positions['qr']));

    expect(fn () => $this->manager->saveFieldPositions($this->actor, $template, $positions))
        ->toThrow(TemplateOverlayObscuresQrException::class);

    // No force override exists for the QR case.
    expect(fn () => $this->manager->saveFieldPositions($this->actor, $template, $positions, force: true))
        ->toThrow(TemplateOverlayObscuresQrException::class);
});

test('saveFieldPositions warns but allows forcing past coverage over a non-QR field', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');
    $positions = validPositionsFor($template);
    $this->manager->uploadFrontOverlay($this->actor, $template, pngWithOpaqueBox(1011, 638, $positions['photo']));

    expect(fn () => $this->manager->saveFieldPositions($this->actor, $template, $positions))
        ->toThrow(TemplateFieldObscuredException::class);

    expect(fn () => $this->manager->saveFieldPositions($this->actor, $template, $positions, force: true))
        ->not->toThrow(Exception::class);

    expect($template->fresh()->field_positions_front)->not->toBeNull();
});

test('activate refuses an incomplete template', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');

    expect(fn () => $this->manager->activate($this->actor, $template))
        ->toThrow(InvalidArgumentException::class);
});

test('activate deactivates the previously active template for the same id_type', function () {
    $first = completeTemplate($this->manager, $this->actor, 'owner');
    $this->manager->activate($this->actor, $first);

    $second = completeTemplate($this->manager, $this->actor, 'owner');
    $this->manager->activate($this->actor, $second);

    expect($first->fresh()->is_active)->toBeFalse();
    expect($second->fresh()->is_active)->toBeTrue();
});

test('activating a template for a different id_type does not touch the other active template', function () {
    $owner = completeTemplate($this->manager, $this->actor, 'owner');
    $this->manager->activate($this->actor, $owner);

    $tenant = completeTemplate($this->manager, $this->actor, 'tenant');
    $this->manager->activate($this->actor, $tenant);

    expect($owner->fresh()->is_active)->toBeTrue();
    expect($tenant->fresh()->is_active)->toBeTrue();
});

test('Template::activeFor resolves the active template per id_type, or null', function () {
    expect(Template::activeFor('owner'))->toBeNull();

    $template = completeTemplate($this->manager, $this->actor, 'owner');
    $this->manager->activate($this->actor, $template);

    expect(Template::activeFor('owner')->id)->toBe($template->id);
    expect(Template::activeFor('tenant'))->toBeNull();
});

test('delete refuses a template that has issued cards', function () {
    $template = completeTemplate($this->manager, $this->actor, 'owner');
    IdCard::factory()->create(['template_id' => $template->id]);

    expect(fn () => $this->manager->delete($this->actor, $template))
        ->toThrow(InvalidArgumentException::class);

    expect($template->fresh())->not->toBeNull();
});

test('delete removes a template with no cards and its stored artwork', function () {
    $template = completeTemplate($this->manager, $this->actor, 'owner');
    $frontPath = $template->overlay_path_front;

    $this->manager->delete($this->actor, $template);

    expect(Template::find($template->id))->toBeNull();
    Storage::disk('local')->assertMissing($frontPath);
});
