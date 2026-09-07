<?php

use App\Livewire\Concerns\HasSortableColumns;
use App\Models\Unit;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use HasSortableColumns, WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Unit::class);
    }

    protected function sortableColumns(): array
    {
        return [
            'building_code' => 'building_code',
            'floor_code' => 'floor_code',
            'unit_number' => 'unit_number',
        ];
    }

    public function with(): array
    {
        $query = Unit::query();

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('building_code', 'ilike', $like)
                    ->orWhere('floor_code', 'ilike', $like)
                    ->orWhere('unit_number', 'ilike', $like);
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
                    <div>
                        <x-input-label for="search" :value="__('Search')" />
                        <x-text-input wire:model.live.debounce.300ms="search" id="search" class="block mt-1 w-64" type="text" placeholder="{{ __('Unit code') }}" />
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
                        <th class="py-2 pr-4">{{ __('Primary owner') }}</th>
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($units as $unit)
                        <tr class="border-b" wire:key="unit-{{ $unit->id }}">
                            <td class="py-2 pr-4 font-mono">{{ $unit->unitCode() }}</td>
                            <td class="py-2 pr-4">{{ $unit->floor_code }}</td>
                            <td class="py-2 pr-4">{{ $unit->unit_number }}</td>
                            <td class="py-2 pr-4">
                                {{ $unit->primaryOwnerRelationship()?->person?->displayName() ?? __('— none (integrity issue) —') }}
                            </td>
                            <td class="py-2">
                                <a href="{{ route('units.show', $unit) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                    {{ __('View') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="5" />
                    @endforelse
                </x-data-table>
            </div>
        </div>
    </div>
</div>
