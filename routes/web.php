<?php

use App\Http\Controllers\FontFileController;
use App\Http\Controllers\IdCardQrController;
use App\Http\Controllers\IdCardRenderController;
use App\Http\Controllers\PersonPhotoController;
use App\Livewire\Pages\Dev\ComponentsPreview;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// First-run bootstrap (architecture §12). Guest-accessible by necessity —
// no account exists yet — and gated by EnsureSystemIsBootstrapped, which
// refuses this route once an active Superadmin exists.
Volt::route('setup', 'pages.setup.wizard')->name('setup');

// No public landing page. By the time this runs, EnsureSystemIsBootstrapped
// (a global 'web' middleware — see bootstrap/app.php) has already redirected
// every request to /setup while the system is unclaimed, so the two cases
// left to decide between here are simpler than the three the user actually
// experiences: signed in goes to the dashboard, everyone else goes to login.
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Volt::route('users', 'pages.users.index')
    ->middleware(['auth'])
    ->name('users.index');

Volt::route('audit', 'pages.audit.index')
    ->middleware(['auth'])
    ->name('audit.index');

// People (Phase 6). Route order matters: 'people/create' must be registered
// before 'people/{person}' or Laravel resolves 'create' as a person id.
Volt::route('people', 'pages.people.index')
    ->middleware(['auth'])
    ->name('people.index');

Volt::route('people/create', 'pages.people.create')
    ->middleware(['auth'])
    ->name('people.create');

Volt::route('people/{person}', 'pages.people.show')
    ->middleware(['auth'])
    ->name('people.show');

Route::get('people/{person}/photo', PersonPhotoController::class)
    ->middleware(['auth'])
    ->name('people.photo');

// Units & relationships (Phase 7).
Volt::route('units', 'pages.units.index')
    ->middleware(['auth'])
    ->name('units.index');

Volt::route('units/create', 'pages.units.create')
    ->middleware(['auth'])
    ->name('units.create');

// ->withTrashed(): a deleted unit's restore screen lives at this same route.
Volt::route('units/{unit}', 'pages.units.show')
    ->middleware(['auth'])
    ->name('units.show')
    ->withTrashed();

// QR & verification (Phase 10). Verify is open to every role — a Reader
// account exists for exactly this screen.
Volt::route('verify', 'pages.verify.index')
    ->middleware(['auth'])
    ->name('verify.index');

Route::get('id-cards/{idCard}/qr', IdCardQrController::class)
    ->middleware(['auth'])
    ->name('id-cards.qr');

// Card issuance and lifecycle (Phase 12 — "Issue ID" deferred from Phase 8,
// "Card lifecycle" deferred from Phase 9). Route order matters, same reason
// as people/units above: 'id-cards/issue' before 'id-cards/{idCard}'.
Volt::route('id-cards', 'pages.id-cards.index')
    ->middleware(['auth'])
    ->name('id-cards.index');

Volt::route('id-cards/issue', 'pages.id-cards.issue')
    ->middleware(['auth'])
    ->name('id-cards.issue');

Volt::route('id-cards/{idCard}', 'pages.id-cards.show')
    ->middleware(['auth'])
    ->name('id-cards.show');

// Rendered front/back (Phase 12, architecture §10) — composited fresh on
// every request, never cached to disk. Gated by manageLifecycle(), not the
// broader view() a Reader also holds — see IdCardRenderController's own
// docblock for why a Reader must never reach these two routes.
Route::get('id-cards/{idCard}/render/front', [IdCardRenderController::class, 'front'])
    ->middleware(['auth'])
    ->name('id-cards.render.front');

Route::get('id-cards/{idCard}/render/back', [IdCardRenderController::class, 'back'])
    ->middleware(['auth'])
    ->name('id-cards.render.back');

// Templates (Phase 12, architecture §10). Superadmin-only end to end —
// TemplatePolicy is what actually enforces it; route order matters, same
// reason as above: 'templates/create' before 'templates/{template}'.
Volt::route('templates', 'pages.templates.index')
    ->middleware(['auth'])
    ->name('templates.index');

Volt::route('templates/create', 'pages.templates.create')
    ->middleware(['auth'])
    ->name('templates.create');

Volt::route('templates/{template}', 'pages.templates.show')
    ->middleware(['auth'])
    ->name('templates.show');

// Card fonts (Phase 12 follow-up, architecture §10). Superadmin-only —
// upload a .ttf, or a .zip of them, and activate one. One page is enough
// for this: no create/show split, unlike Templates.
Volt::route('fonts', 'pages.fonts.index')
    ->middleware(['auth'])
    ->name('fonts.index');

Route::get('fonts/{font}/file', FontFileController::class)
    ->middleware(['auth'])
    ->name('fonts.file');

// Reconciliation dashboard (Phase 11, architecture §14). Superadmin/Admin
// only, gated by the 'view-reconciliation-dashboard' Gate (registered in
// AppServiceProvider — no single model backs this screen).
Volt::route('reconciliation', 'pages.reconciliation.index')
    ->middleware(['auth'])
    ->name('reconciliation.index');

// Phase 5's own "done when": the component library renders in a
// component-preview route. `local`-only — same reasoning as the dev
// seeder (CLAUDE.md 25): a gallery of every component with working demo
// state is a developer tool, not something a production LAN deployment
// should ever expose, registered or not.
//
// A class component, not Volt::route() — see
// App\Livewire\Pages\Dev\ComponentsPreview's own docblock for why.
if (app()->environment('local')) {
    Route::get('dev/components', ComponentsPreview::class)
        ->middleware(['auth'])
        ->name('dev.components');
}

require __DIR__.'/auth.php';
