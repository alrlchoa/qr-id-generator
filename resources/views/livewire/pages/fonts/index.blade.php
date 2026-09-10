<?php

use App\Models\Font;
use App\Services\FontManager;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public $upload = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Font::class);
    }

    public function with(): array
    {
        return [
            'fonts' => Font::orderByDesc('is_active')->orderByDesc('created_at')->get(),
        ];
    }

    public function uploadFont(FontManager $fonts): void
    {
        $this->authorize('create', Font::class);

        $this->validate(['upload' => ['required', 'file']]);

        try {
            $created = $fonts->upload(auth()->user(), $this->upload);
        } catch (InvalidArgumentException $e) {
            $this->addError('upload', $e->getMessage());

            return;
        }

        $this->reset('upload');
        session()->flash('status', __(':count font(s) added.', ['count' => count($created)]));
    }

    public function activate(int $fontId, FontManager $fonts): void
    {
        $this->authorize('update', $font = Font::findOrFail($fontId));

        $fonts->activate(auth()->user(), $font);
    }

    public function deactivate(int $fontId, FontManager $fonts): void
    {
        $this->authorize('update', $font = Font::findOrFail($fontId));

        $fonts->deactivate(auth()->user(), $font);
    }

    public function delete(int $fontId, FontManager $fonts): void
    {
        $font = Font::findOrFail($fontId);
        $this->authorize('delete', $font);

        // No validate() call happens here, so — unlike uploadFont() above
        // — nothing clears a previous failure's message on its own; a
        // stale error would otherwise persist even after a later,
        // different font's delete succeeds.
        $this->resetErrorBag('delete');

        try {
            $fonts->delete(auth()->user(), $font);
        } catch (InvalidArgumentException $e) {
            $this->addError('delete', $e->getMessage());
        }
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Card fonts') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-50 text-green-800 rounded-lg text-sm">{{ session('status') }}</div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <p class="text-sm text-gray-600">
                    {{ __('The active font is what every rendered card\'s name, unit number, and role text uses. With none active, cards still render — text falls back to a built-in, coarser-shrinking font rather than failing.') }}
                </p>

                <form wire:submit="uploadFont" class="flex items-end gap-4">
                    <div>
                        <x-input-label for="upload" :value="__('Upload a .ttf, or a .zip of several')" />
                        <input type="file" wire:model="upload" id="upload" accept=".ttf,.zip" class="block mt-1 text-sm">
                    </div>
                    <x-primary-button type="submit">{{ __('Upload') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('upload')" class="mt-1" />
                <x-input-error :messages="$errors->get('delete')" class="mt-1" />
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <x-data-table>
                    <x-slot name="head">
                        <th class="py-2 pr-4">{{ __('Name') }}</th>
                        <th class="py-2 pr-4">{{ __('File') }}</th>
                        <th class="py-2 pr-4">{{ __('Status') }}</th>
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($fonts as $font)
                        <tr class="border-b" wire:key="font-{{ $font->id }}">
                            <td class="py-2 pr-4">{{ $font->name }}</td>
                            <td class="py-2 pr-4 text-gray-500">{{ $font->original_filename }}</td>
                            <td class="py-2 pr-4">
                                @if ($font->is_active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{{ __('Active') }}</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ __('Inactive') }}</span>
                                @endif
                            </td>
                            <td class="py-2 space-x-3">
                                @if ($font->is_active)
                                    <button type="button" wire:click="deactivate({{ $font->id }})" class="underline text-sm text-gray-600 hover:text-gray-900">
                                        {{ __('Deactivate') }}
                                    </button>
                                @else
                                    <button type="button" wire:click="activate({{ $font->id }})" class="underline text-sm text-gray-600 hover:text-gray-900">
                                        {{ __('Activate') }}
                                    </button>
                                    <button type="button" wire:click="delete({{ $font->id }})" wire:confirm="{{ __('Delete this font?') }}" class="underline text-sm text-red-600 hover:text-red-900">
                                        {{ __('Delete') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="4" />
                    @endforelse
                </x-data-table>
            </div>
        </div>
    </div>
</div>
