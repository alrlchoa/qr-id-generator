@props(['name', 'options', 'label', 'placeholder' => 'Search by ID number or name'])

{{--
    A searchable "ID number - Name" dropdown over a person list handed in
    as `:options` (each `{id_number, label}`). Filters client-side via
    Alpine — the option list for an admin's own condo roster is small
    enough that no server round-trip is worth the complexity. Selecting an
    option writes the person's `user_id_number` straight to the named
    Livewire property via `$wire.set()`, matching what the rest of this
    form's plain ID-number text inputs already validate against.
--}}
<div x-data="{
        open: false,
        query: '',
        options: @js($options),
        get filtered() {
            const q = this.query.toLowerCase().trim();
            if (q === '') return this.options;
            return this.options.filter(o => o.label.toLowerCase().includes(q));
        },
        select(option) {
            $wire.set('{{ $name }}', option.id_number);
            this.query = option.label;
            this.open = false;
        },
    }" class="relative">
    <x-input-label :for="$name.'_search'" :value="$label" />
    <x-text-input
        x-model="query"
        x-on:focus="open = true"
        x-on:click.outside="open = false"
        :id="$name.'_search'"
        class="block mt-1 w-full"
        type="text"
        :placeholder="__($placeholder)"
        autocomplete="off"
    />

    <div x-show="open && filtered.length > 0" x-transition class="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-md shadow-lg max-h-60 overflow-auto" style="display: none;">
        <template x-for="option in filtered" :key="option.id_number">
            <div
                x-on:click="select(option)"
                x-text="option.label"
                class="px-3 py-2 text-sm cursor-pointer hover:bg-indigo-50"
            ></div>
        </template>
    </div>

    <div x-show="open && filtered.length === 0" class="absolute z-10 mt-1 w-full bg-white border border-gray-300 rounded-md shadow-lg px-3 py-2 text-sm text-gray-500" style="display: none;">
        {{ __('No matches.') }}
    </div>

    <x-input-error :messages="$errors->get($name)" class="mt-2" />
</div>
