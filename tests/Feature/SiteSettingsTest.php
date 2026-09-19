<?php

use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

// Phase 16: site branding — the settings page, what it saves, and where the
// name, logo and navbar colour show up.

beforeEach(function () {
    Storage::fake('local');
});

// The first path of Laravel's default logo SVG — gone once Phase 16 lands.
const LARAVEL_LOGO_PATH = 'M305.8 81.125';

test('only a Superadmin can open site settings', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->superadmin()->create())->get('/settings/site')->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get('/settings/site')->assertForbidden();
    $this->actingAs(User::factory()->reader()->create())->get('/settings/site')->assertForbidden();
});

test('a guest is sent to login, not shown site settings', function () {
    bootstrapSystem();

    $this->get('/settings/site')->assertRedirect(route('login'));
});

test('an Admin cannot save site settings by calling the page directly', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    Volt::test('pages.settings.site')->assertForbidden();

    expect(SiteSetting::current()->site_name)->toBeNull()
        ->and(AuditLog::count())->toBe(0);
});

test('only Superadmins see Site settings in the account menu', function () {
    bootstrapSystem();

    $this->actingAs(User::factory()->superadmin()->create())->get('/dashboard')->assertSee(route('settings.site'));
    $this->actingAs(User::factory()->admin()->create())->get('/dashboard')->assertDontSee(route('settings.site'));
});

test('with nothing set, the defaults show and Laravel\'s logo is gone', function () {
    bootstrapSystem();

    $login = $this->get('/login');
    $login->assertOk()
        ->assertSee(config('app.name'))
        ->assertSee('<title>'.e(config('app.name')).'</title>', false)
        ->assertDontSee(LARAVEL_LOGO_PATH, false)
        ->assertDontSee('branding/logo', false);

    $this->actingAs(User::factory()->admin()->create())->get('/dashboard')
        ->assertSee('background-color: #ffffff', false)
        ->assertDontSee(LARAVEL_LOGO_PATH, false);
});

test('renaming the site shows the new name on the login page, the navbar and the tab title, audited', function () {
    bootstrapSystem();
    $superadmin = User::factory()->superadmin()->create();
    $this->actingAs($superadmin);

    Volt::test('pages.settings.site')
        ->set('siteName', '  Tower   A Residences ')
        ->call('saveName')
        ->assertHasNoErrors();

    expect(SiteSetting::current()->site_name)->toBe('Tower A Residences');

    $log = AuditLog::where('action', 'site_name_changed')->sole();
    expect($log->user_id)->toBe($superadmin->id)
        ->and($log->previous_value)->toBe(['site_name' => config('app.name')])
        ->and($log->new_value)->toBe(['site_name' => 'Tower A Residences']);

    $this->get('/dashboard')
        ->assertSee('Tower A Residences')
        ->assertSee('<title>Tower A Residences</title>', false);

    auth()->logout();
    $this->get('/login')->assertSee('Tower A Residences');
});

test('a site name is escaped wherever it shows', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')->set('siteName', '<b>Tower</b>')->call('saveName')->assertHasNoErrors();

    $this->get('/dashboard')->assertSee('&lt;b&gt;Tower&lt;/b&gt;', false)->assertDontSee('<b>Tower</b>', false);
});

test('a blank or too-long site name is refused and changes nothing', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')->set('siteName', '   ')->call('saveName')->assertHasErrors('siteName');
    Volt::test('pages.settings.site')->set('siteName', str_repeat('a', 61))->call('saveName')->assertHasErrors('siteName');

    expect(SiteSetting::current()->site_name)->toBeNull()
        ->and(AuditLog::count())->toBe(0);
});

test('saving the name it already has writes no audit row', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')->set('siteName', config('app.name'))->call('saveName')->assertHasNoErrors();

    expect(AuditLog::count())->toBe(0);
});

test('a dark navbar colour is saved, audited, and gets light text', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')
        ->set('navbarColor', '#1E3A8A')
        ->call('saveColor')
        ->assertHasNoErrors();

    expect(SiteSetting::current()->navbar_color)->toBe('#1e3a8a');

    $log = AuditLog::where('action', 'navbar_color_changed')->sole();
    expect($log->previous_value)->toBe(['navbar_color' => '#ffffff'])
        ->and($log->new_value)->toBe(['navbar_color' => '#1e3a8a']);

    $html = $this->get('/dashboard')->getContent();
    expect($html)->toContain('background-color: #1e3a8a')
        ->and($html)->toContain('text-white/80');
});

test('a light navbar colour keeps dark text', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')->set('navbarColor', '#fde68a')->call('saveColor')->assertHasNoErrors();

    $html = $this->get('/dashboard')->getContent();
    expect($html)->toContain('background-color: #fde68a')
        ->and($html)->not->toContain('text-white/80');
});

test('an invalid colour is refused and changes nothing', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    foreach (['red', '#12345', '#1234567', 'url(x)', '#12345g'] as $bad) {
        Volt::test('pages.settings.site')->set('navbarColor', $bad)->call('saveColor')->assertHasErrors('navbarColor');
    }

    expect(SiteSetting::current()->navbar_color)->toBeNull()
        ->and(AuditLog::count())->toBe(0);
});

test('resetting the colour goes back to white', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')->set('navbarColor', '#111111')->call('saveColor');
    Volt::test('pages.settings.site')->call('resetColor');

    expect(SiteSetting::current()->navbarColor())->toBe('#ffffff')
        ->and(AuditLog::where('action', 'navbar_color_changed')->count())->toBe(2);
});

test('a square PNG logo is stored as a 512px PNG, audited, served publicly, and shown on the login page', function () {
    bootstrapSystem();
    $superadmin = User::factory()->superadmin()->create();
    $this->actingAs($superadmin);

    Volt::test('pages.settings.site')
        ->set('logo', UploadedFile::fake()->image('logo.png', 800, 800))
        ->call('uploadLogo')
        ->assertHasNoErrors();

    $settings = SiteSetting::current();
    expect($settings->logo_path)->toStartWith('branding/')
        ->and(Storage::disk('local')->exists($settings->logo_path))->toBeTrue();

    $stored = getimagesizefromstring((string) Storage::disk('local')->get($settings->logo_path));
    expect([$stored[0], $stored[1], $stored['mime']])->toBe([512, 512, 'image/png']);

    $log = AuditLog::where('action', 'site_logo_changed')->sole();
    expect($log->user_id)->toBe($superadmin->id)
        ->and($log->new_value['logo_path'])->toBe($settings->logo_path);

    auth()->logout();

    $this->get(route('branding.logo'))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    $this->get('/login')
        ->assertSee($settings->logoUrl(), false)
        ->assertDontSee(LARAVEL_LOGO_PATH, false);
});

test('a small square JPEG logo is kept at its own size, as a PNG', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')
        ->set('logo', UploadedFile::fake()->image('logo.jpg', 200, 200))
        ->call('uploadLogo')
        ->assertHasNoErrors();

    $stored = getimagesizefromstring((string) Storage::disk('local')->get(SiteSetting::current()->logo_path));
    expect([$stored[0], $stored[1], $stored['mime']])->toBe([200, 200, 'image/png']);
});

test('a 2400px logo is accepted and scaled down', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    // A real logo this size was once refused by a 2048px limit. A palette
    // PNG keeps this test's own memory small; the service still decodes
    // and resizes every pixel of it.
    $image = imagecreate(2400, 2400);
    imagecolorallocate($image, 30, 58, 138);
    ob_start();
    imagepng($image, null, 9);
    $png = (string) ob_get_clean();
    imagedestroy($image);

    Volt::test('pages.settings.site')
        ->set('logo', UploadedFile::fake()->createWithContent('logo.png', $png))
        ->call('uploadLogo')
        ->assertHasNoErrors();

    $stored = Storage::disk('local')->get(SiteSetting::current()->logo_path);

    expect(getimagesizefromstring($stored))->toMatchArray([0 => 512, 1 => 512, 'mime' => 'image/png']);
});

test('a replacement logo removes the previous file', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')->set('logo', UploadedFile::fake()->image('a.png', 100, 100))->call('uploadLogo');
    $first = SiteSetting::current()->logo_path;

    Volt::test('pages.settings.site')->set('logo', UploadedFile::fake()->image('b.png', 100, 100))->call('uploadLogo');
    $second = SiteSetting::current()->logo_path;

    expect($second)->not->toBe($first)
        ->and(Storage::disk('local')->exists($first))->toBeFalse()
        ->and(Storage::disk('local')->exists($second))->toBeTrue();
});

test('a logo that is not square, too large, too big in pixels, or not a PNG/JPEG is refused', function (UploadedFile $upload) {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')
        ->set('logo', $upload)
        ->call('uploadLogo')
        ->assertHasErrors('logo');

    expect(SiteSetting::current()->logo_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('branding'))->toBe([])
        ->and(AuditLog::count())->toBe(0);
})->with([
    'not square' => fn () => UploadedFile::fake()->image('wide.png', 400, 200),
    'over 1 MB' => fn () => UploadedFile::fake()->image('big.png', 100, 100)->size(2048),
    // Only a PNG header claiming 3001 × 3001 — no pixel data. The size is
    // read from the header and refused before anything is decoded, which is
    // the point: decoding an image that big is what could exhaust memory
    // (and a real one here once did, for the whole test run).
    'over 3000 px' => fn () => UploadedFile::fake()->createWithContent(
        'huge.png',
        "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 3001, 3001)."\x08\x06\x00\x00\x00".pack('N', 0),
    ),
    'a GIF' => fn () => UploadedFile::fake()->image('logo.gif', 100, 100),
    'an SVG' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script></svg>'),
]);

test('a refused logo\'s error clears on the next attempt', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')
        ->set('logo', UploadedFile::fake()->image('wide.png', 400, 200))
        ->call('uploadLogo')
        ->assertHasErrors('logo')
        ->set('logo', UploadedFile::fake()->image('square.png', 100, 100))
        ->call('uploadLogo')
        ->assertHasNoErrors();
});

test('removing the logo deletes it, is audited, and brings back the default mark', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());

    Volt::test('pages.settings.site')->set('logo', UploadedFile::fake()->image('logo.png', 100, 100))->call('uploadLogo');
    $path = SiteSetting::current()->logo_path;

    Volt::test('pages.settings.site')->call('removeLogo');

    expect(SiteSetting::current()->logo_path)->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(AuditLog::where('action', 'site_logo_removed')->count())->toBe(1);

    $this->get(route('branding.logo'))->assertNotFound();
});

test('a user who must change their password can still load the logo', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->superadmin()->create());
    Volt::test('pages.settings.site')->set('logo', UploadedFile::fake()->image('logo.png', 100, 100))->call('uploadLogo');

    // Every other route sends this user to the change-password page, which
    // shows the logo — so the logo itself must not redirect.
    $this->actingAs(User::factory()->admin()->create(['must_change_password' => true]));

    $this->get('/dashboard')->assertRedirect(route('password.change'));
    $this->get(route('branding.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');
});

test('the logo route answers without signing in, and before the system is set up', function () {
    // No bootstrapSystem(): every other route redirects to /setup now. The
    // logo route must not, or the setup page's logo is a broken image.
    $this->get('/branding/logo')->assertNotFound();
    $this->get('/dashboard')->assertRedirect(route('setup'));
});
