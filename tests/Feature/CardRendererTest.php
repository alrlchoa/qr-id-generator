<?php

use App\Models\IdCard;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Models\User;
use App\Services\CardRenderer;
use App\Services\TemplateManager;
use Illuminate\Support\Facades\Storage;

// transparentPng(), validPositionsFor(), and completeTemplate() are shared
// helpers in tests/Pest.php.

beforeEach(function () {
    Storage::fake('local');
    $this->actor = User::factory()->admin()->create();
    $this->manager = app(TemplateManager::class);
    $this->renderer = app(CardRenderer::class);
});

test('renderFront produces a PNG at the template canvas size', function () {
    $template = completeTemplate($this->manager, $this->actor, 'owner');
    $this->manager->activate($this->actor, $template);

    $unit = Unit::factory()->create();
    PersonUnitRelationship::factory()->primaryOwner()->create(['unit_id' => $unit->id]);
    $person = Person::factory()->create(['photo_path' => null]);
    $card = IdCard::factory()->create([
        'person_id' => $person->id,
        'unit_id' => $unit->id,
        'type' => 'owner',
        'template_id' => $template->id,
    ]);

    $bytes = $this->renderer->renderFront($card);
    $image = imagecreatefromstring($bytes);

    expect($image)->not->toBeFalse();
    expect(imagesx($image))->toBe(1011);
    expect(imagesy($image))->toBe(638);
});

test('renderBack produces a PNG at the template canvas size', function () {
    $template = completeTemplate($this->manager, $this->actor, 'owner');
    $card = IdCard::factory()->create(['type' => 'owner', 'template_id' => $template->id]);

    $bytes = $this->renderer->renderBack($card);
    $image = imagecreatefromstring($bytes);

    expect(imagesx($image))->toBe(1011);
    expect(imagesy($image))->toBe(638);
});

test('renderFront refuses a card with no template on record', function () {
    $card = IdCard::factory()->create(['type' => 'owner', 'template_id' => null]);

    expect(fn () => $this->renderer->renderFront($card))->toThrow(InvalidArgumentException::class);
});

test('renderBack refuses a template with no back artwork uploaded', function () {
    $template = $this->manager->createTemplate($this->actor, 'owner', 'x', 'landscape');
    $this->manager->uploadFrontOverlay($this->actor, $template, transparentPng(1011, 638));
    $this->manager->saveFieldPositions($this->actor, $template, validPositionsFor($template));
    $card = IdCard::factory()->create(['type' => 'owner', 'template_id' => $template->id]);

    expect(fn () => $this->renderer->renderBack($card))->toThrow(InvalidArgumentException::class);
});

test('renderFront draws the QR field as a real scannable-looking module grid, not a blank box', function () {
    $template = completeTemplate($this->manager, $this->actor, 'employee');
    $card = IdCard::factory()->create(['type' => 'employee', 'unit_id' => null, 'template_id' => $template->id]);

    $bytes = $this->renderer->renderFront($card);
    $image = imagecreatefromstring($bytes);

    $positions = $template->field_positions_front;
    $qrBox = $positions['qr'];

    // Sample the QR box for a mix of black and white pixels — a blank
    // (unrendered) box would be uniformly white.
    $blackSeen = false;

    for ($y = $qrBox['y']; $y < $qrBox['y'] + $qrBox['height']; $y += 3) {
        for ($x = $qrBox['x']; $x < $qrBox['x'] + $qrBox['width']; $x += 3) {
            $rgb = imagecolorat($image, $x, $y);
            $colors = imagecolorsforindex($image, $rgb);

            if ($colors['red'] < 50 && $colors['green'] < 50 && $colors['blue'] < 50) {
                $blackSeen = true;
                break 2;
            }
        }
    }

    expect($blackSeen)->toBeTrue();
});

test('employee card front omits the unit field and has no unit box to render', function () {
    $template = completeTemplate($this->manager, $this->actor, 'employee');

    expect($template->placeableFields())->not->toContain('unit_number');
    expect(array_keys($template->field_positions_front))->not->toContain('unit_number');
});
