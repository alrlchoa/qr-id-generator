<?php

use App\Livewire\Concerns\HasSortableColumns;
use App\Models\Unit;
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

    /**
     * Superadmin-only (re-checked at render time, not just hidden — the
     * same shape the People index's ID-number toggle uses). Off by
     * default: a live roster is the normal thing to browse, and a deleted
     * unit is a recovery case, not routine browsing.
     */
    public bool $showDeleted = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Unit::class);
    }

    /**
     * `primary_owner` sorts by the active primary owner's name — a
     * company's `legal_name`, or a natural person's last name (matching
     * the People index's own name ordering). Needs the join below since
     * the owner's name lives on `people`, not `units`.
     */
    protected function sortableColumns(): array
    {
        return [
            'building_code' => 'building_code',
            'floor_code' => 'floor_code',
            'unit_number' => 'unit_number',
            'primary_owner' => DB::raw("coalesce(owner.legal_name, owner.last_name, owner.first_name)"),
        ];
    }

    public function with(): array
    {
        $query = Unit::query()
            ->select('units.*')
            ->leftJoin('person_unit_relationships as pur', function ($join) {
                $join->on('pur.unit_id', '=', 'units.id')
                    ->where('pur.is_primary_owner', true)
                    ->whereNull('pur.ended_at');
            })
            ->leftJoin('people as owner', 'owner.id', '=', 'pur.person_id');

        if ($this->showDeleted && auth()->user()->isSuperadmin()) {
            $query->withTrashed();
        }

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('units.building_code', 'ilike', $like)
                    ->orWhere('units.floor_code', 'ilike', $like)
                    ->orWhere('units.unit_number', 'ilike', $like);
            });
        }

        $this->applySort($query);

        return [
            'units' => $query->paginate(20),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Units') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div class="flex flex-wrap items-end gap-4">
                        <div>
                            <x-input-label for="search" :value="__('Search')" />
                            <x-text-input wire:model.live.debounce.300ms="search" id="search" class="block mt-1 w-64" type="text" placeholder="{{ __('Unit code') }}" />
                        </div>

                        @if (auth()->user()->isSuperadmin())
                            <label class="inline-flex items-center gap-2 pb-2">
                                <input type="checkbox" wire:model.live="showDeleted" class="rounded border-gray-300">
                                <span class="text-sm text-gray-700">{{ __('Show deleted units') }}</span>
                            </label>
                        @endif
                    </div>

                    @can('create', Unit::class)
                        <a href="{{ route('units.create') }}" wire:navigate>
                            <x-primary-button type="button">{{ __('+ Create') }}</x-primary-button>
                        </a>
                    @endcan
                </div>

                <x-data-table :paginator="$units">
                    <x-slot name="head">
                        <x-data-table.sort-header column="building_code" :current="$sortColumn" :direction="$sortDirection">{{ __('Bldg') }}</x-data-table.sort-header>
                        <x-data-table.sort-header column="floor_code" :current="$sortColumn" :direction="$sortDirection">{{ __('Floor') }}</x-data-table.sort-header>
                        <x-data-table.sort-header column="unit_number" :current="$sortColumn" :direction="$sortDirection">{{ __('Unit') }}</x-data-table.sort-header>
                        <x-data-table.sort-header column="primary_owner" :current="$sortColumn" :direction="$sortDirection">{{ __('Primary owner') }}</x-data-table.sort-header>
                        @if ($showDeleted && auth()->user()->isSuperadmin())
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                        @endif
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($units as $unit)
                        <tr class="border-b {{ $unit->trashed() ? 'bg-red-50' : '' }}" wire:key="unit-{{ $unit->id }}">
                            <td class="py-2 pr-4 font-mono">{{ $unit->building_code ?: '—' }}</td>
                            <td class="py-2 pr-4">{{ $unit->floor_code }}</td>
                            <td class="py-2 pr-4">{{ $unit->unit_number }}</td>
                            <td class="py-2 pr-4">
                                @if ($unit->trashed())
                                    {{ __('— deleted —') }}
                                @else
                                    {{ $unit->primaryOwnerRelationship()?->person?->displayName() ?? __('— none (integrity issue) —') }}
                                @endif
                            </td>
                            @if ($showDeleted && auth()->user()->isSuperadmin())
                                <td class="py-2 pr-4">
                                    @if ($unit->trashed())
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{{ __('Deleted') }}</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{{ __('Active') }}</span>
                                    @endif
                                </td>
                            @endif
                            <td class="py-2">
                                <a href="{{ route('units.show', $unit) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                    {{ $unit->trashed() ? __('View / Restore') : __('View') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty :colspan="$showDeleted && auth()->user()->isSuperadmin() ? 6 : 5" />
                    @endforelse
                </x-data-table>
            </div>
        </div>
    </div>
</div>
