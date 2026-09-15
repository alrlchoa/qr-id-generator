@props([
    'name' => null,
    'title' => null,
    'confirmLabel' => null,
    'confirmAction' => null,
    'cancelLabel' => null,
    'cancelAction' => null,
    'danger' => false,
    'open' => null,
])

{{--
    The Confirm-or-Cancel pattern (docs/design/wireframes.md). Two variants
    by intent, not by markup:

    - `danger="false"` (default) — mandatory reissue (§9.3), relationship
      cascade (§5.3). These have no decline path once the underlying save
      happens, but the dialog ITSELF is still cancel-or-confirm.
    - `danger="true"` — soft-delete confirmation. Genuinely cancellable with
      no consequence, styled to match the weight of what it's asking.

    And two modes, by who decides whether it is showing:

    - **Event-driven** — `name`, no `open`. Built on Breeze's <x-modal>,
      opened with `$dispatch('open-modal', '{name}')`; both buttons close it
      in the browser the instant they are clicked. Right for a dialog with
      nothing to validate inside it — any error its Confirm raises is never
      seen there, which is why units/show's and people/show's contract-end-
      date dialogs flash their errors instead.
    - **Server-driven** — `:open="…"` plus `cancelAction` (Phase 14). The
      dialog is in the page only while the bound PHP state is true, and both
      buttons call Livewire rather than closing anything client-side: the
      next render removes it, or keeps it open with a validation error on
      show. This is Phase 12's lesson kept inside the shared component — a
      dialog whose visibility is PHP state needs no Alpine at all (an
      x-show bound to a Blade literal never re-evaluates after a Livewire
      morph, and the dialog silently never opened). Same markup as
      <x-modal>'s panel, without the transitions.

    `confirmAction` / `cancelAction` are Livewire method names.
--}}
@if ($open === null)
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
                    {{ $cancelLabel ?? __('Cancel') }}
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
@elseif ($open)
    <div class="fixed inset-0 overflow-y-auto px-4 py-6 sm:px-0 z-50" role="dialog" aria-modal="true">
        <div class="fixed inset-0 transform">
            <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
        </div>

        <div class="mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform sm:w-full sm:max-w-md sm:mx-auto">
            <div class="p-6">
                @if ($title)
                    <h2 class="text-lg font-medium text-gray-900">{{ $title }}</h2>
                @endif

                <div class="mt-2 text-sm text-gray-600">
                    {{ $slot }}
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-secondary-button type="button" wire:click="{{ $cancelAction }}">
                        {{ $cancelLabel ?? __('Cancel') }}
                    </x-secondary-button>

                    @if ($confirmAction)
                        @if ($danger)
                            <x-danger-button type="button" wire:click="{{ $confirmAction }}">
                                {{ $confirmLabel ?? __('Confirm') }}
                            </x-danger-button>
                        @else
                            <x-primary-button type="button" wire:click="{{ $confirmAction }}">
                                {{ $confirmLabel ?? __('Confirm') }}
                            </x-primary-button>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>
@endif
