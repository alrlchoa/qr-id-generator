<?php

use App\Livewire\Concerns\HasSortableColumns;
use App\Models\Person;
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

    public function mount(): void
    {
        $this->authorize('viewAny', Person::class);
    }

    protected function sortableColumns(): array
    {
        return [
            'name' => 'COALESCE(legal_name, last_name, first_name)',
            'user_id_number' => 'user_id_number',
        ];
    }

    public function with(): array
    {
        $query = Person::query();

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
                    </div>

                    @can('create', Person::class)
                        <a href="{{ route('people.create') }}" wire:navigate>
                            <x-primary-button type="button">{{ __('+ Create') }}</x-primary-button>
                        </a>
                    @endcan
                </div>

                <x-data-table :paginator="$people">
                    <x-slot name="head">
                        <x-data-table.sort-header column="name" :current="$sortColumn" :direction="$sortDirection">{{ __('Name') }}</x-data-table.sort-header>
                        <x-data-table.sort-header column="user_id_number" :current="$sortColumn" :direction="$sortDirection">{{ __('ID Number') }}</x-data-table.sort-header>
                        <th class="py-2 pr-4">{{ __('Kind') }}</th>
                        <th class="py-2 pr-4">{{ __('Photo') }}</th>
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($people as $person)
                        <tr class="border-b" wire:key="person-{{ $person->id }}">
                            <td class="py-2 pr-4">{{ $person->displayName() }}</td>
                            <td class="py-2 pr-4 font-mono">{{ $person->user_id_number }}</td>
                            <td class="py-2 pr-4">{{ $person->isCompany() ? __('Company') : __('Natural person') }}</td>
                            <td class="py-2 pr-4">{{ $person->photo_path ? __('Yes') : __('No') }}</td>
                            <td class="py-2">
                                <a href="{{ route('people.show', $person) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
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
