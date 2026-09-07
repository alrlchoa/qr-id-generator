@props(['column', 'current' => '', 'direction' => 'asc'])

{{--
    One sortable <th>. `column` must be a key in the calling screen's own
    sortableColumns() allowlist (App\Livewire\Concerns\HasSortableColumns)
    — this component only renders the affordance and fires sortBy($column);
    it never decides what's sortable, and never touches SQL.
--}}
<th class="py-2 pr-4">
    <button type="button" wire:click="sortBy('{{ $column }}')" class="inline-flex items-center gap-1 font-medium hover:text-gray-700">
        {{ $slot }}
        @if ($current === $column)
            <span aria-hidden="true">{{ $direction === 'asc' ? '▲' : '▼' }}</span>
            <span class="sr-only">{{ $direction === 'asc' ? __('sorted ascending') : __('sorted descending') }}</span>
        @endif
    </button>
</th>
