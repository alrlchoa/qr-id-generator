<?php

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
