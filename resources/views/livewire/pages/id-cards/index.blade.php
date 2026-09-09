<?php

use App\Livewire\Concerns\HasSortableColumns;
use App\Models\IdCard;
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
        $this->authorize('manageLifecycle', IdCard::class);
    }

    protected function sortableColumns(): array
    {
        return [
            'control_number' => 'control_number',
            'type' => 'type',
            'status' => 'status',
            'issued_at' => 'issued_at',
        ];
    }

    public function with(): array
    {
        $query = IdCard::query()->with(['person', 'unit']);

        if ($this->search !== '') {
            $like = '%'.$this->search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('control_number', 'ilike', $like)
                    ->orWhereHas('person', function ($personQuery) use ($like) {
                        $personQuery->where('first_name', 'ilike', $like)
                            ->orWhere('last_name', 'ilike', $like)
                            ->orWhere('legal_name', 'ilike', $like);
                    });
            });
        }

        $this->applySort($query);

        return [
            'cards' => $query->paginate(20),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('ID Cards') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <x-input-label for="search" :value="__('Search')" />
                        <x-text-input wire:model.live.debounce.300ms="search" id="search" class="block mt-1 w-64" type="text" placeholder="{{ __('Control number or name') }}" />
                    </div>

                    @can('create', \App\Models\IdCard::class)
                        <a href="{{ route('id-cards.issue') }}" wire:navigate>
                            <x-primary-button type="button">{{ __('+ Issue an ID') }}</x-primary-button>
                        </a>
                    @endcan
                </div>

                <x-data-table :paginator="$cards">
                    <x-slot name="head">
                        <x-data-table.sort-header column="control_number" :current="$sortColumn" :direction="$sortDirection">{{ __('Control #') }}</x-data-table.sort-header>
                        <th class="py-2 pr-4">{{ __('Person') }}</th>
                        <x-data-table.sort-header column="type" :current="$sortColumn" :direction="$sortDirection">{{ __('Type') }}</x-data-table.sort-header>
                        <x-data-table.sort-header column="status" :current="$sortColumn" :direction="$sortDirection">{{ __('Status') }}</x-data-table.sort-header>
                        <x-data-table.sort-header column="issued_at" :current="$sortColumn" :direction="$sortDirection">{{ __('Issued') }}</x-data-table.sort-header>
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($cards as $card)
                        <tr class="border-b" wire:key="card-{{ $card->id }}">
                            <td class="py-2 pr-4 font-mono">{{ $card->control_number }}</td>
                            <td class="py-2 pr-4">{{ $card->person->displayName() }}</td>
                            <td class="py-2 pr-4 capitalize">{{ $card->type }}</td>
                            <td class="py-2 pr-4"><x-status-badge :status="$card->status" /></td>
                            <td class="py-2 pr-4">{{ $card->issued_at?->format('Y-m-d') }}</td>
                            <td class="py-2">
                                <a href="{{ route('id-cards.show', $card) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                    {{ __('View') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="6" />
                    @endforelse
                </x-data-table>
            </div>
        </div>
    </div>
</div>
