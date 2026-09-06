@props([
    'wire',
    'id',
    'label',
    'toggle',
    'track' => null,
    'autocomplete' => 'new-password',
])

{{--
    A password input with a show/hide toggle.

    `toggle` and `track` are names of Alpine properties owned by an enclosing
    x-data — this component does not declare its own scope, so the same
    x-data can drive both the toggle and whatever live validation the parent
    needs. `track` is optional: omit it where nothing is comparing values.

    The static type="password" stays alongside x-bind:type so the field is
    still masked if Alpine never runs. A reveal toggle that fails open would
    be worse than no toggle at all.
--}}
<div class="mt-4">
    <x-input-label :for="$id" :value="$label" />

    <div class="relative">
        <x-text-input
            wire:model="{{ $wire }}"
            @if ($track) x-on:input="{{ $track }} = $event.target.value" @endif
            x-bind:type="{{ $toggle }} ? 'text' : 'password'"
            id="{{ $id }}"
            class="block mt-1 w-full pe-10"
            type="password"
            required
            autocomplete="{{ $autocomplete }}" />

        {{-- type="button": the default inside a form is submit, which would
             make the reveal toggle create the accounts. --}}
        <button type="button"
                x-on:click="{{ $toggle }} = ! {{ $toggle }}"
                x-bind:aria-pressed="{{ $toggle }}"
                x-bind:aria-label="{{ $toggle }} ? '{{ __('Hide password') }}' : '{{ __('Show password') }}'"
                tabindex="-1"
                class="absolute inset-y-0 end-0 mt-1 flex items-center pe-3 text-gray-500 hover:text-gray-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 rounded-e-md">
            <svg x-show="! {{ $toggle }}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            <svg x-show="{{ $toggle }}" style="display: none" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
            </svg>
        </button>
    </div>

    {{ $slot }}

    <x-input-error :messages="$errors->get($wire)" class="mt-2" />
</div>
