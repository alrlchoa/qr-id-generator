<?php

use App\Models\SiteSetting;
use App\Services\SiteSettingsManager;
use App\Support\ColorContrast;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public string $siteName = '';

    public string $navbarColor = '';

    public $logo = null;

    public function mount(): void
    {
        $this->authorize('manage-site-settings');

        $settings = SiteSetting::current();
        $this->siteName = $settings->siteName();
        $this->navbarColor = $settings->navbarColor();
    }

    public function saveName(SiteSettingsManager $settings): void
    {
        $this->authorize('manage-site-settings');

        $this->validate(['siteName' => ['required', 'string', 'max:'.SiteSetting::MAX_NAME_LENGTH]]);

        try {
            $settings->rename(auth()->user(), $this->siteName);
        } catch (InvalidArgumentException $e) {
            $this->addError('siteName', $e->getMessage());

            return;
        }

        $this->saved(__('Site name saved.'));
    }

    public function saveColor(SiteSettingsManager $settings): void
    {
        $this->authorize('manage-site-settings');

        $this->validate(
            ['navbarColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/']],
            ['navbarColor.regex' => __('Pick a colour, or type one as #rrggbb.')],
        );

        $settings->changeNavbarColor(auth()->user(), $this->navbarColor);

        $this->saved(__('Navbar colour saved.'));
    }

    public function resetColor(SiteSettingsManager $settings): void
    {
        $this->authorize('manage-site-settings');

        $settings->changeNavbarColor(auth()->user(), SiteSetting::DEFAULT_NAVBAR_COLOR);

        $this->saved(__('Navbar colour reset to white.'));
    }

    public function uploadLogo(SiteSettingsManager $settings): void
    {
        $this->authorize('manage-site-settings');

        // The service's refusals arrive by addError(), which no validate()
        // call clears on the next attempt — CLAUDE.md 62.
        $this->resetErrorBag('logo');

        $this->validate(
            ['logo' => ['required', 'file', 'max:1024']],
            ['logo.max' => __('The logo must be 1 MB or smaller.')],
        );

        try {
            $settings->replaceLogo(auth()->user(), $this->logo);
        } catch (InvalidArgumentException $e) {
            $this->addError('logo', $e->getMessage());

            return;
        }

        $this->saved(__('Logo saved.'));
    }

    public function removeLogo(SiteSettingsManager $settings): void
    {
        $this->authorize('manage-site-settings');

        $settings->removeLogo(auth()->user());

        $this->saved(__('Logo removed — the default mark is back.'));
    }

    /**
     * A reload, not a re-render: the navbar and the page title live outside
     * this component and only pick up the change on the next page load.
     */
    private function saved(string $message): void
    {
        session()->flash('status', $message);

        $this->redirectRoute('settings.site', navigate: true);
    }

    public function with(): array
    {
        $current = SiteSetting::current();
        $candidate = strtolower($this->navbarColor);
        $preview = preg_match('/^#[0-9a-f]{6}$/', $candidate) ? $candidate : $current->navbarColor();

        return [
            'current' => $current,
            'previewColor' => $preview,
            'previewDark' => ColorContrast::prefersLightText($preview),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Site settings') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <x-toast :message="session('status')" />

            {{-- Site name --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Site name') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Shown on the login screen, in the navigation bar and in the browser tab. Up to :max characters.', ['max' => \App\Models\SiteSetting::MAX_NAME_LENGTH]) }}
                    </p>
                </header>

                <form wire:submit="saveName" class="space-y-4 max-w-md">
                    <x-form-field name="siteName" :label="__('Name')">
                        <x-text-input wire:model="siteName" id="siteName" class="block mt-1 w-full" type="text" maxlength="60" required />
                    </x-form-field>

                    <x-primary-button>{{ __('Save name') }}</x-primary-button>
                </form>
            </div>

            {{-- Logo --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Logo') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Shown on the login screen and in the navigation bar. A square PNG or JPEG, 1 MB and 3000 × 3000 pixels at most — it\'s stored as a PNG of at most 512 × 512 pixels, with transparency kept. SVG isn\'t accepted.') }}
                    </p>
                </header>

                <div class="flex items-center gap-4">
                    <div class="w-24 h-24 shrink-0 rounded-md border border-gray-200 bg-gray-50 flex items-center justify-center p-2">
                        <x-application-logo class="w-20 h-20 fill-current text-gray-500" />
                    </div>
                    <p class="text-sm text-gray-600">
                        {{ $current->logo_path ? __('Your logo.') : __('No logo uploaded yet — this is the default mark.') }}
                    </p>
                </div>

                <form wire:submit="uploadLogo" class="space-y-4">
                    <x-form-field name="logo" :label="__('New logo')">
                        <input type="file" wire:model="logo" id="logo" accept="image/png,image/jpeg" class="block mt-1 text-sm">
                    </x-form-field>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-primary-button>{{ __('Upload logo') }}</x-primary-button>

                        @if ($current->logo_path)
                            <x-danger-button type="button" wire:click="removeLogo" wire:confirm="{{ __('Remove the logo and go back to the default mark?') }}">
                                {{ __('Remove logo') }}
                            </x-danger-button>
                        @endif
                    </div>
                </form>
            </div>

            {{-- Navbar colour --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <header>
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Navigation bar colour') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Any colour. The text on it switches between dark and light automatically, so it stays readable.') }}
                    </p>
                </header>

                <form wire:submit="saveColor" class="space-y-4">
                    <div class="flex flex-wrap items-end gap-4">
                        <div>
                            <x-input-label for="navbarColorPicker" :value="__('Colour')" />
                            <input type="color" wire:model.live.debounce.200ms="navbarColor" id="navbarColorPicker" class="mt-1 h-10 w-16 rounded border border-gray-300 bg-white p-1">
                        </div>

                        <x-form-field name="navbarColor" :label="__('Hex code')">
                            <x-text-input wire:model.live.debounce.400ms="navbarColor" id="navbarColor" class="block mt-1 w-32 font-mono" type="text" maxlength="7" />
                        </x-form-field>
                    </div>

                    {{-- Preview: the same contrast rule the navbar itself uses. --}}
                    <div class="rounded-md border border-gray-200 overflow-hidden" aria-label="{{ __('Preview') }}">
                        <div class="h-14 px-4 flex items-center gap-6 text-sm font-medium" style="background-color: {{ $previewColor }}">
                            <span class="font-semibold {{ $previewDark ? 'text-white' : 'text-gray-800' }}">{{ $current->siteName() }}</span>
                            <span class="border-b-2 pb-1 {{ $previewDark ? 'text-white border-white' : 'text-gray-900 border-indigo-400' }}">{{ __('Dashboard') }}</span>
                            <span class="{{ $previewDark ? 'text-white/80' : 'text-gray-500' }}">{{ __('Verify') }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-primary-button>{{ __('Save colour') }}</x-primary-button>
                        <x-secondary-button type="button" wire:click="resetColor">{{ __('Reset to white') }}</x-secondary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
