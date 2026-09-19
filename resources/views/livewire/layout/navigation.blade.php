<?php

use App\Livewire\Actions\Logout;
use App\Models\SiteSetting;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect(route('login', absolute: false), navigate: true);
    }

    /**
     * The site's branding (Phase 16): its name beside the logo, and the
     * navbar colour a Superadmin chose. `navDark` is worked out once here —
     * light text on a dark colour, dark text on a light one — and every
     * link below is told which.
     */
    public function with(): array
    {
        $branding = SiteSetting::current();

        return [
            'branding' => $branding,
            'navDark' => $branding->navbarIsDark(),
        ];
    }
}; ?>

{{--
    The desktop link row appears at `xl` (1280px), not Breeze's `sm`
    (640px). A Superadmin's ten links measured 1,043px of row plus the
    page's side padding (2026-09-15), so from 640px to ~1,100px the `sm`
    row overflowed and every logged-in page scrolled sideways; `lg`
    (1024px) would still overflow. Below `xl` the hamburger menu takes
    over. See docs/design/responsive-and-accessibility.md.

    Phase 16's site name sits beside the logo, capped at 8rem so the row
    still fits under 1280px; the full name is in its title attribute.

    The navbar colour is applied inline: Tailwind only builds classes it
    finds in the source, so a colour chosen at runtime can't be a class.
    The text colours switch between two sets that are in the build.
--}}
@php
    $textStrong = $navDark ? 'text-white' : 'text-gray-800';
    $textMuted = $navDark ? 'text-white/80' : 'text-gray-500';
@endphp
<nav x-data="{ open: false }" class="border-b {{ $navDark ? 'border-black/20' : 'border-gray-100' }}" style="background-color: {{ $branding->navbarColor() }}">
    <!-- Primary Navigation Menu -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo and site name -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route(auth()->user()->homeRoute()) }}" wire:navigate class="flex items-center">
                        <x-application-logo class="block h-9 w-auto fill-current {{ $textStrong }}" />
                        <span class="ms-3 max-w-[8rem] truncate text-sm font-semibold {{ $textStrong }}" title="{{ $branding->siteName() }}">{{ $branding->siteName() }}</span>
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden space-x-8 xl:-my-px xl:ms-10 xl:flex">
                    <x-nav-item route="dashboard" :label="__('Dashboard')" :dark="$navDark" />
                    <x-nav-item route="verify.index" :label="__('Verify')" :dark="$navDark" />

                    @if (auth()->user()->isSuperadmin())
                        <x-nav-item route="users.index" :label="__('Users')" :dark="$navDark" />
                    @endif

                    @if (auth()->user()->isSuperadmin() || auth()->user()->isAdmin())
                        <x-nav-item route="people.index" :label="__('People')" :dark="$navDark" />
                        <x-nav-item route="units.index" :label="__('Units')" :dark="$navDark" />
                        <x-nav-item route="id-cards.index" :label="__('ID Cards')" :dark="$navDark" />
                        <x-nav-item route="reconciliation.index" :label="__('Reconciliation')" :dark="$navDark" />
                        <x-nav-item route="audit.index" :label="__('Audit Log')" :dark="$navDark" />
                    @endif

                    @if (auth()->user()->isSuperadmin())
                        <x-nav-item route="templates.index" :label="__('Templates')" :dark="$navDark" />
                        <x-nav-item route="fonts.index" :label="__('Fonts')" :dark="$navDark" />
                    @endif
                </div>
            </div>

            <!-- Settings Dropdown -->
            <div class="hidden xl:flex xl:items-center xl:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md bg-transparent focus:outline-none transition ease-in-out duration-150 {{ $navDark ? 'text-white/90 hover:text-white' : 'text-gray-500 hover:text-gray-700' }}">
                            <div x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile')" wire:navigate>
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        @can('manage-site-settings')
                            <x-dropdown-link :href="route('settings.site')" wire:navigate>
                                {{ __('Site settings') }}
                            </x-dropdown-link>
                        @endcan

                        <!-- Authentication -->
                        <button wire:click="logout" class="w-full text-start">
                            <x-dropdown-link>
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </button>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center xl:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md focus:outline-none transition duration-150 ease-in-out {{ $navDark ? 'text-white/80 hover:text-white hover:bg-white/10 focus:bg-white/10 focus:text-white' : 'text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:bg-gray-100 focus:text-gray-500' }}">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden xl:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-nav-item route="dashboard" :label="__('Dashboard')" :mobile="true" :dark="$navDark" />
            <x-nav-item route="verify.index" :label="__('Verify')" :mobile="true" :dark="$navDark" />

            @if (auth()->user()->isSuperadmin())
                <x-nav-item route="users.index" :label="__('Users')" :mobile="true" :dark="$navDark" />
            @endif

            @if (auth()->user()->isSuperadmin() || auth()->user()->isAdmin())
                <x-nav-item route="people.index" :label="__('People')" :mobile="true" :dark="$navDark" />
                <x-nav-item route="units.index" :label="__('Units')" :mobile="true" :dark="$navDark" />
                <x-nav-item route="id-cards.index" :label="__('ID Cards')" :mobile="true" :dark="$navDark" />
                <x-nav-item route="reconciliation.index" :label="__('Reconciliation')" :mobile="true" :dark="$navDark" />
                <x-nav-item route="audit.index" :label="__('Audit Log')" :mobile="true" :dark="$navDark" />
            @endif

            @if (auth()->user()->isSuperadmin())
                <x-nav-item route="templates.index" :label="__('Templates')" :mobile="true" :dark="$navDark" />
                <x-nav-item route="fonts.index" :label="__('Fonts')" :mobile="true" :dark="$navDark" />
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t {{ $navDark ? 'border-white/20' : 'border-gray-200' }}">
            <div class="px-4">
                <div class="font-medium text-base {{ $textStrong }}" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm {{ $textMuted }}">{{ auth()->user()->username }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" :dark="$navDark" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                @can('manage-site-settings')
                    <x-responsive-nav-link :href="route('settings.site')" :active="request()->routeIs('settings.site')" :dark="$navDark" wire:navigate>
                        {{ __('Site settings') }}
                    </x-responsive-nav-link>
                @endcan

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link :dark="$navDark">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
