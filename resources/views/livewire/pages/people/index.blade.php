<?php

use App\Livewire\Concerns\HasSortableColumns;
use App\Models\Person;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use HasSortableColumns, WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public bool $needsPhoto = false;

    /**
     * The ID Number column is Superadmin-only — an Admin never sees it and
     * never gets the toggle either; re-checked at render time (not just
     * hidden), so tampering with this property directly can't surface it
     * for anyone else. Off by default even for a Superadmin: it's an
     * internal identifier, not something every visit to this page needs.
     */
    public bool $showIdNumber = false;

    /**
     * Superadmin-only, off by default, re-checked at render time — the
     * same shape as `showIdNumber` above and the Units index's own
     * `showDeleted` toggle.
     */
    public bool $showDeleted = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Person::class);
    }

    /**
     * `name` sorts natural persons by last name then first name, grouped
     * before companies (sorted separately, by legal name) — one text sort
     * key rather than several comma-joined `orderBy()` calls, since the
     * trait appends a single trailing direction to whatever string comes
     * back here and a multi-expression string would get that direction
     * appended to only the last clause, breaking the SQL.
     */
    protected function sortableColumns(): array
    {
        return [
            'name' => DB::raw("case when entity_type = 'company' then '1|' || coalesce(legal_name, '') else '0|' || last_name || '|' || coalesce(first_name, '') end"),
            'user_id_number' => 'user_id_number',
            'kind' => 'entity_type',
        ];
    }

    public function with(): array
    {
        $query = Person::query();

        if ($this->showDeleted && auth()->user()->isSuperadmin()) {
            $query->withTrashed();
        }

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('user_id_number', 'ilike', $like)
                    ->orWhere('first_name', 'ilike', $like)
                    ->orWhere('middle_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhere('legal_name', 'ilike', $like);
            });
        }

        if ($this->needsPhoto) {
            $query->whereNull('photo_path')
                ->whereHas('relationships', fn ($q) => $q->whereNull('ended_at'));
        }

        $this->applySort($query);

        return [
            'people' => $query->paginate(20),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('People') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="flex flex-wrap items-end gap-4">
                        <div>
                            <x-input-label for="search" :value="__('Search')" />
                            <x-text-input wire:model.live.debounce.300ms="search" id="search" class="block mt-1 w-64" type="text" placeholder="{{ __('Name, legal name, or ID number') }}" />
                        </div>

                        <label class="inline-flex items-center gap-2 pb-2">
                            <input type="checkbox" wire:model.live="needsPhoto" class="rounded border-gray-300">
                            <span class="text-sm text-gray-700">{{ __('Active relationship, no photo') }}</span>
                        </label>

                        @if (auth()->user()->isSuperadmin())
                            <label class="inline-flex items-center gap-2 pb-2">
                                <input type="checkbox" wire:model.live="showIdNumber" class="rounded border-gray-300">
                                <span class="text-sm text-gray-700">{{ __('Show ID number column') }}</span>
                            </label>

                            <label class="inline-flex items-center gap-2 pb-2">
                                <input type="checkbox" wire:model.live="showDeleted" class="rounded border-gray-300">
                                <span class="text-sm text-gray-700">{{ __('Show deleted people') }}</span>
                            </label>
                        @endif
                    </div>

                    @can('create', Person::class)
                        <a href="{{ route('people.create') }}" wire:navigate>
                            <x-primary-button type="button">{{ __('+ Create') }}</x-primary-button>
                        </a>
                    @endcan
                </div>

                @php
                    $showIdColumn = auth()->user()->isSuperadmin() && $showIdNumber;
                    $showDeletedColumn = auth()->user()->isSuperadmin() && $showDeleted;
                @endphp

                <x-data-table :paginator="$people">
                    <x-slot name="head">
                        <x-data-table.sort-header column="name" :current="$sortColumn" :direction="$sortDirection">{{ __('Name') }}</x-data-table.sort-header>
                        @if ($showIdColumn)
                            <x-data-table.sort-header column="user_id_number" :current="$sortColumn" :direction="$sortDirection">{{ __('ID Number') }}</x-data-table.sort-header>
                        @endif
                        <x-data-table.sort-header column="kind" :current="$sortColumn" :direction="$sortDirection">{{ __('Kind') }}</x-data-table.sort-header>
                        <th class="py-2 pr-4">{{ __('Photo') }}</th>
                        @if ($showDeletedColumn)
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                        @endif
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($people as $person)
                        <tr class="border-b {{ $person->trashed() ? 'bg-red-50' : '' }}" wire:key="person-{{ $person->id }}">
                            <td class="py-2 pr-4">{{ $person->displayName() }}</td>
                            @if ($showIdColumn)
                                <td class="py-2 pr-4 font-mono">{{ $person->user_id_number }}</td>
                            @endif
                            <td class="py-2 pr-4">{{ $person->isCompany() ? __('Company') : __('Natural person') }}</td>
                            <td class="py-2 pr-4">{{ $person->photo_path ? __('Yes') : __('No') }}</td>
                            @if ($showDeletedColumn)
                                <td class="py-2 pr-4">
                                    @if ($person->trashed())
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{{ __('Deleted') }}</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{{ __('Active') }}</span>
                                    @endif
                                </td>
                            @endif
                            <td class="py-2">
                                @if ($person->trashed())
                                    <span class="text-sm text-gray-400">{{ __('—') }}</span>
                                @else
                                    <a href="{{ route('people.show', $person) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                        {{ __('View') }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty :colspan="4 + ($showIdColumn ? 1 : 0) + ($showDeletedColumn ? 1 : 0)" />
                    @endforelse
                </x-data-table>
            </div>
        </div>
    </div>
</div>
