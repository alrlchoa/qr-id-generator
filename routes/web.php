<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// First-run bootstrap (architecture §12). Guest-accessible by necessity —
// no account exists yet — and gated by EnsureSystemIsBootstrapped, which
// refuses this route once an active Superadmin exists.
Volt::route('setup', 'pages.setup.wizard')->name('setup');

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Volt::route('users', 'pages.users.index')
    ->middleware(['auth'])
    ->name('users.index');

require __DIR__.'/auth.php';
