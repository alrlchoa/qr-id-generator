@props(['on', 'variant' => 'success'])

{{--
    A transient, auto-hiding flash message tied to a named Livewire event
    (Phase 5 plan: "toast/flash messages"). Generalizes the existing
    <x-action-message> pattern (Breeze's "Saved.", still used as-is on the
    profile page) with a variant colour and a longer, more toast-like
    display window — <x-action-message> stays for the one spot it already
    fits; this is for everything else that needs a dismissible confirmation
    or error after a Livewire action.

    Fire it from a component method with `$this->dispatch('{on}')`.
--}}
@php
$styles = [
    'success' => 'text-green-800 bg-green-50 border-green-200',
    'error' => 'text-red-800 bg-red-50 border-red-200',
][$variant] ?? 'text-gray-700 bg-gray-50 border-gray-200';
@endphp

<div
    x-data="{ shown: false, timeout: null }"
    x-init="@this.on('{{ $on }}', () => { clearTimeout(timeout); shown = true; timeout = setTimeout(() => { shown = false }, 3000); })"
    x-show="shown"
    x-transition:enter="ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="ease-in duration-300"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    style="display: none;"
    role="status"
    {{ $attributes->merge(['class' => "border rounded-md px-4 py-2 text-sm {$styles}"]) }}
>
    {{ $slot }}
</div>
