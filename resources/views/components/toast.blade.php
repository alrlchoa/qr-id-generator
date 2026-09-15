@props(['on' => null, 'message' => null, 'variant' => 'success'])

{{--
    The one component for a screen's confirmation or error message (Phase 5
    plan: "toast/flash messages"; CLAUDE.md rule 48). Two modes:

    - **Server-driven** — `:message="session('status')"`, or
      `:message="session('error')" variant="error"`. Renders where it is
      placed, stays until the next render, and renders nothing when the
      message is empty. Every screen uses this since Phase 14: a flash
      message is PHP state, and one that fades on a timer can take an
      error with it before anyone has read it (explicit user decision,
      2026-09-15 — "keep them on screen").
    - **Event-driven** — `on="event-name"`, fired from a component method
      with `$this->dispatch('event-name')`: a transient toast showing its
      slot, auto-hidden after 3 seconds. Phase 5's original design, still
      demonstrated on /dev/components. Note that a dispatched event does
      not survive a redirect; a flashed message does.

    <x-action-message> (Breeze's "Saved.") stays on the profile page, and
    the login page keeps Breeze's <x-auth-session-status> — password
    rotation and the setup wizard redirect there, and the guest layout
    carries its own component for that.
--}}
@php
$styles = [
    'success' => 'text-green-800 bg-green-50 border-green-200',
    'error' => 'text-red-800 bg-red-50 border-red-200',
][$variant] ?? 'text-gray-700 bg-gray-50 border-gray-200';
@endphp

@if ($on)
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
@elseif (filled($message))
    <div
        role="{{ $variant === 'error' ? 'alert' : 'status' }}"
        {{ $attributes->merge(['class' => "border rounded-md px-4 py-2 text-sm {$styles}"]) }}
    >
        {{ $message }}
    </div>
@endif
