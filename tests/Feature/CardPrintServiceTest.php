<?php

use App\Models\IdCard;
use App\Models\User;
use App\Services\CardPrintService;
use App\Services\TemplateManager;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->actor = User::factory()->superadmin()->create();
    $this->prints = app(CardPrintService::class);
});

test('print returns a zip containing front and back PNGs and marks the card printed', function () {
    $template = completeTemplate(app(TemplateManager::class), $this->actor, 'owner');
    app(TemplateManager::class)->activate($this->actor, $template);
    $card = IdCard::factory()->create(['type' => 'owner', 'template_id' => $template->id]);

    $zipBytes = $this->prints->print($this->actor, $card);

    $path = tempnam(sys_get_temp_dir(), 'ziptest').'.zip';
    file_put_contents($path, $zipBytes);
    $zip = new ZipArchive;
    $zip->open($path);

    expect($zip->numFiles)->toBe(2);
    expect($zip->locateName("{$card->control_number}-front.png"))->not->toBeFalse();
    expect($zip->locateName("{$card->control_number}-back.png"))->not->toBeFalse();

    $zip->close();
    unlink($path);

    expect($card->fresh()->isPrinted())->toBeTrue();
    expect($card->fresh()->printed_at)->not->toBeNull();
});

test('print refuses a lost, revoked, or expired card', function (string $status) {
    $template = completeTemplate(app(TemplateManager::class), $this->actor, 'owner');
    $card = IdCard::factory()->create(['type' => 'owner', 'template_id' => $template->id, 'status' => $status]);

    expect(fn () => $this->prints->print($this->actor, $card))
        ->toThrow(InvalidArgumentException::class);

    expect($card->fresh()->isPrinted())->toBeFalse();
})->with(['lost', 'revoked', 'expired']);

test('print refuses a card that has already been printed', function () {
    $template = completeTemplate(app(TemplateManager::class), $this->actor, 'owner');
    $card = IdCard::factory()->create(['type' => 'owner', 'template_id' => $template->id, 'printed_at' => now()]);

    expect(fn () => $this->prints->print($this->actor, $card))
        ->toThrow(InvalidArgumentException::class);
});

test('print refuses a card with no template, same as rendering itself', function () {
    $card = IdCard::factory()->create(['template_id' => null]);

    expect(fn () => $this->prints->print($this->actor, $card))
        ->toThrow(InvalidArgumentException::class);

    expect($card->fresh()->isPrinted())->toBeFalse();
});

test('printing is orthogonal to status — a printed card keeps its own status', function () {
    $template = completeTemplate(app(TemplateManager::class), $this->actor, 'owner');
    $card = IdCard::factory()->create(['type' => 'owner', 'template_id' => $template->id, 'status' => 'active']);

    $this->prints->print($this->actor, $card);

    expect($card->fresh()->status)->toBe('active');
    expect($card->fresh()->isPrinted())->toBeTrue();
});
