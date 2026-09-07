@props(['name', 'title' => null, 'confirmLabel' => null, 'confirmAction' => null, 'danger' => false])

{{--
    The Confirm-or-Cancel pattern (docs/design/wireframes.md), built on the
    existing Breeze <x-modal>. Two variants by intent, not by markup:

    - `danger="false"` (default) — mandatory reissue (§9.3), relationship
      cascade (§5.3). These have no decline path once the underlying save
      happens, but the dialog ITSELF is still cancel-or-confirm.
    - `danger="true"` — soft-delete confirmation. Genuinely cancellable with
      no consequence, styled to match the weight of what it's asking.

    Opened via `$dispatch('open-modal', '{name}')` from the triggering
    button (standard Breeze modal convention); `confirmAction` is the
    Livewire method name the Confirm button calls.
--}}
<x-modal :name="$name" max-width="md">
    <div class="p-6">
        @if ($title)
            <h2 class="text-lg font-medium text-gray-900">{{ $title }}</h2>
        @endif

        <div class="mt-2 text-sm text-gray-600">
            {{ $slot }}
        </div>

        <div class="mt-6 flex justify-end gap-3">
            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', '{{ $name }}')">
                {{ __('Cancel') }}
            </x-secondary-button>

            @if ($confirmAction)
                @if ($danger)
                    <x-danger-button type="button" wire:click="{{ $confirmAction }}" x-on:click="$dispatch('close-modal', '{{ $name }}')">
                        {{ $confirmLabel ?? __('Confirm') }}
                    </x-danger-button>
                @else
                    <x-primary-button type="button" wire:click="{{ $confirmAction }}" x-on:click="$dispatch('close-modal', '{{ $name }}')">
                        {{ $confirmLabel ?? __('Confirm') }}
                    </x-primary-button>
                @endif
            @endif
        </div>
    </div>
</x-modal>
