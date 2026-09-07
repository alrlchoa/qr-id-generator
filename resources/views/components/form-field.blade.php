@props(['name', 'label', 'hint' => null])

{{--
    Composes the label/input/error trio that appears on every form in this
    app (Phase 5 plan: "form field wrapper"). The input itself stays in the
    slot rather than being generated here — a select, a textarea, and a
    text input all need this same wrapper, and forcing one input shape on
    all of them would fight the screens that need something else.
--}}
<div class="mt-4">
    <x-input-label :for="$name" :value="$label" />

    {{ $slot }}

    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif

    <x-input-error :messages="$errors->get($name)" class="mt-2" />
</div>
