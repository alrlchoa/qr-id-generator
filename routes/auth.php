<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('login', 'pages.auth.login')
        ->name('login');
});

Route::middleware('auth')->group(function () {
    Volt::route('change-password', 'pages.auth.change-password')
        ->name('password.change');
});
