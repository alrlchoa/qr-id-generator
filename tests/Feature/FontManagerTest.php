<?php

use App\Models\Font;
use App\Models\User;
use App\Services\FontManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Storage;

/**
 * A byte string with a real sfnt magic number but garbage after it — passes
 * FontManager's header sniff, then fails GD's own imagettfbbox() check.
 * This is what a "looks like a font, isn't one" upload actually is.
 */
function headerOnlyFakeTtfBytes(): string
{
    return "\x00\x01\x00\x00".str_repeat('not a real font table', 20);
}

beforeEach(function () {
    Storage::fake('local');
    $this->actor = User::factory()->superadmin()->create();
    $this->manager = app(FontManager::class);
});

test('uploading a file with the wrong extension is refused', function () {
    $file = File::createWithContent('notes.txt', 'plain text');

    expect(fn () => $this->manager->upload($this->actor, $file))
        ->toThrow(InvalidArgumentException::class);
});

test('a .ttf that fails the magic-byte check is refused before GD ever sees it', function () {
    $file = File::createWithContent('fake.ttf', 'this is not a font at all');

    expect(fn () => $this->manager->upload($this->actor, $file))
        ->toThrow(InvalidArgumentException::class);

    expect(Font::count())->toBe(0);
});

test('a .ttf with a real magic number but a corrupt body is refused by GD\'s own check', function () {
    $file = File::createWithContent('fake.ttf', headerOnlyFakeTtfBytes());

    expect(fn () => $this->manager->upload($this->actor, $file))
        ->toThrow(InvalidArgumentException::class);

    expect(Font::count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('card-fonts');
});

test('a zip with no .ttf entries at all is refused', function () {
    $zipPath = tempnam(sys_get_temp_dir(), 'zip').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'nothing here');
    $zip->close();

    $file = File::createWithContent('fonts.zip', file_get_contents($zipPath));

    expect(fn () => $this->manager->upload($this->actor, $file))
        ->toThrow(InvalidArgumentException::class);

    unlink($zipPath);
});

test('a zip whose .ttf entries are all invalid is refused, not silently accepted', function () {
    $zipPath = tempnam(sys_get_temp_dir(), 'zip').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('Regular.ttf', headerOnlyFakeTtfBytes());
    $zip->addFromString('Bold.ttf', 'not even the right header');
    $zip->close();

    $file = File::createWithContent('fonts.zip', file_get_contents($zipPath));

    expect(fn () => $this->manager->upload($this->actor, $file))
        ->toThrow(InvalidArgumentException::class);

    expect(Font::count())->toBe(0);
    unlink($zipPath);
});

test('activate deactivates the previously active font first', function () {
    $first = Font::factory()->active()->create();
    $second = Font::factory()->create();

    $this->manager->activate($this->actor, $second);

    expect($first->fresh()->is_active)->toBeFalse();
    expect($second->fresh()->is_active)->toBeTrue();
});

test('at most one font can be active — the partial unique index actually holds', function () {
    Font::factory()->active()->create();

    expect(fn () => Font::factory()->active()->create())
        ->toThrow(QueryException::class);
});

test('deactivate clears the active flag', function () {
    $font = Font::factory()->active()->create();

    $this->manager->deactivate($this->actor, $font);

    expect($font->fresh()->is_active)->toBeFalse();
});

test('delete refuses the active font', function () {
    $font = Font::factory()->active()->create();

    expect(fn () => $this->manager->delete($this->actor, $font))
        ->toThrow(InvalidArgumentException::class);

    expect(Font::find($font->id))->not->toBeNull();
});

test('delete removes an inactive font and its stored file', function () {
    Storage::disk('local')->put('card-fonts/test.ttf', 'bytes');
    $font = Font::factory()->create(['storage_path' => 'card-fonts/test.ttf']);

    $this->manager->delete($this->actor, $font);

    expect(Font::find($font->id))->toBeNull();
    Storage::disk('local')->assertMissing('card-fonts/test.ttf');
});

test('Font::activeFont resolves the active row, or null', function () {
    expect(Font::activeFont())->toBeNull();

    $font = Font::factory()->active()->create();

    expect(Font::activeFont()->id)->toBe($font->id);
});
