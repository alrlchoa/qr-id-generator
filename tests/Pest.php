<?php

use App\Models\Template;
use App\Models\User;
use App\Services\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// No RefreshDatabase here: these tests hold a row lock open on one database
// connection while a second, independent connection probes it, which needs
// real committed rows visible across connections (see the test file for why).
pest()->extend(TestCase::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Put the system past the first-run wizard (architecture §12).
 *
 * EnsureSystemIsBootstrapped redirects every route to the wizard while no
 * active Superadmin exists, so any test that makes an HTTP request needs
 * the system bootstrapped first — otherwise it asserts against a redirect
 * to /setup rather than the page it meant to test.
 *
 * Deliberately not a global beforeEach: tests that exercise the
 * two-Superadmin invariant count Superadmin rows, and silently seeding two
 * more would break them in a way that looks like a logic bug.
 */
function bootstrapSystem(): void
{
    User::factory()->superadmin()->count(2)->create();
}

/**
 * A fully transparent PNG at the given size (Phase 12) — a template
 * overlay that obscures nothing, the "everything saves cleanly" case for
 * TemplateManager's alpha check.
 */
function transparentPng(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefill($image, 0, 0, $transparent);

    return gdImageToUploadedFile($image, 'front.png');
}

/**
 * A PNG that is fully transparent except for one solid opaque rectangle —
 * used to place opaque coverage exactly over a given field's box, to
 * exercise TemplateManager's alpha check deliberately.
 *
 * @param  array{x: int, y: int, width: int, height: int}  $opaqueBox
 */
function pngWithOpaqueBox(int $width, int $height, array $opaqueBox): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefill($image, 0, 0, $transparent);

    $opaqueColor = imagecolorallocatealpha($image, 200, 30, 30, 0);
    imagefilledrectangle(
        $image,
        $opaqueBox['x'], $opaqueBox['y'],
        $opaqueBox['x'] + $opaqueBox['width'] - 1, $opaqueBox['y'] + $opaqueBox['height'] - 1,
        $opaqueColor,
    );

    return gdImageToUploadedFile($image, 'front.png');
}

/**
 * Illuminate\Http\Testing\File, not a raw `new UploadedFile(...)` — the
 * latter has no public `$name` property, which Livewire's own file-upload
 * test helper (`Testable::set()` on a WithFileUploads property) reads
 * directly. A plain UploadedFile works fine against TemplateManager
 * called directly; only the Volt-page tests that `->set('frontOverlay',
 * ...)` need this specific fake subclass.
 */
function gdImageToUploadedFile(GdImage $image, string $name): UploadedFile
{
    ob_start();
    imagepng($image);
    $content = ob_get_clean();
    imagedestroy($image);

    return File::createWithContent($name, (string) $content);
}

/**
 * A minimal, valid field_positions_front for $template's own id_type —
 * shared across TemplateManagerTest, CardRendererTest, and any future
 * template-editor UI test that needs a real, in-bounds position set
 * rather than repeating the box coordinates in every test.
 *
 * @return array<string, array{x: int, y: int, width: int, height: int}>
 */
function validPositionsFor(Template $template): array
{
    $positions = [
        'photo' => ['x' => 20, 'y' => 20, 'width' => 100, 'height' => 100],
        'name' => ['x' => 140, 'y' => 20, 'width' => 200, 'height' => 30],
        'qr' => ['x' => $template->width_px - 120, 'y' => $template->height_px - 120, 'width' => 100, 'height' => 100],
        'role' => ['x' => 140, 'y' => 60, 'width' => 150, 'height' => 20],
    ];

    if ($template->id_type !== 'employee') {
        $positions['unit_number'] = ['x' => 140, 'y' => 90, 'width' => 150, 'height' => 20];
    }

    return $positions;
}

/**
 * A fully built, activatable-ready template — both overlays uploaded
 * (transparent, so nothing trips the alpha check) and every placeable
 * field positioned — for tests that need a real `isComplete()` template
 * rather than exercising `TemplateManager` step by step themselves.
 */
function completeTemplate(TemplateManager $manager, User $actor, string $idType): Template
{
    $template = $manager->createTemplate($actor, $idType, "{$idType} card", 'landscape');
    $manager->uploadFrontOverlay($actor, $template, transparentPng(1011, 638));
    $manager->uploadBackOverlay($actor, $template, transparentPng(1011, 638));
    $manager->saveFieldPositions($actor, $template, validPositionsFor($template));

    return $template->fresh();
}
