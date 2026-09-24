<?php

use App\Livewire\Concerns\HasSortableColumns;
use App\Models\IdCard;
use App\Services\BulkCardExportService;
use App\Services\CardPrintService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use HasSortableColumns, WithPagination;

    #[Url]
    public string $search = '';

    public bool $confirmingExport = false;

    public function mount(): void
    {
        $this->authorize('manageLifecycle', IdCard::class);
    }

    public function unprintedCount(): int
    {
        return IdCard::query()->where('status', 'active')->whereNull('printed_at')->count();
    }

    public function stageExport(): void
    {
        $this->authorize('manageLifecycle', IdCard::class);
        $this->confirmingExport = true;
    }

    public function cancelExport(): void
    {
        $this->confirmingExport = false;
    }

    /**
     * Same shape as `print()` below — a flashed message rather than
     * `addError()`, since the confirm dialog closes itself the instant
     * Confirm is clicked (rule 48's `<x-confirm-dialog>` note).
     */
    public function exportUnprinted(BulkCardExportService $exports)
    {
        $this->authorize('manageLifecycle', IdCard::class);
        $this->confirmingExport = false;

        try {
            $result = $exports->export(auth()->user());
        } catch (\InvalidArgumentException $e) {
            session()->flash('exportError', $e->getMessage());

            return;
        }

        return response()->download($result['path'], $result['filename'])->deleteFileAfterSend();
    }

    /**
     * The same shape as `pages.id-cards.show`'s own `print()` — deliberately
     * not extracted into a shared trait: two three-line methods with
     * different failure-display targets (a flashed message here, since
     * this is a list row with no per-card error slot; an inline field
     * error there) would cost more to read than the duplication saves.
     */
    public function print(int $idCardId, CardPrintService $prints)
    {
        $card = IdCard::findOrFail($idCardId);
        $this->authorize('manageLifecycle', IdCard::class);

        try {
            $zip = $prints->print(auth()->user(), $card);
        } catch (\InvalidArgumentException $e) {
            session()->flash('printError', $e->getMessage());

            return;
        }

        return response()->streamDownload(function () use ($zip) {
            echo $zip;
        }, "id-card-{$card->control_number}.zip");
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
            <x-toast :message="session('printError')" variant="error" />
            <x-toast :message="session('exportError')" variant="error" />

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <x-input-label for="search" :value="__('Search')" />
                        <x-text-input wire:model.live.debounce.300ms="search" id="search" class="block mt-1 w-64" type="text" placeholder="{{ __('Control number or name') }}" />
                    </div>

                    <div class="flex gap-2">
                        @php $unprintedCount = $this->unprintedCount(); @endphp
                        <x-secondary-button type="button" wire:click="stageExport" :disabled="$unprintedCount === 0">
                            {{ __('Export unprinted cards (:count)', ['count' => $unprintedCount]) }}
                        </x-secondary-button>

                        @can('create', \App\Models\IdCard::class)
                            <a href="{{ route('id-cards.issue') }}" wire:navigate>
                                <x-primary-button type="button">{{ __('+ Issue an ID') }}</x-primary-button>
                            </a>
                        @endcan
                    </div>
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
                            <td class="py-2 space-x-3">
                                <a href="{{ route('id-cards.show', $card) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                    {{ __('View') }}
                                </a>
                                @if ($card->isPrinted())
                                    <span class="text-sm text-gray-400">{{ __('Printed') }}</span>
                                @elseif ($card->status === 'active')
                                    <button type="button" wire:click="print({{ $card->id }})" wire:confirm="{{ __('Download the Smart IDesigner zip and mark this card printed? This cannot be undone.') }}" class="underline text-sm text-indigo-600 hover:text-indigo-900">
                                        {{ __('Print') }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="6" />
                    @endforelse
                </x-data-table>
            </div>
        </div>
    </div>

    <x-confirm-dialog :open="$confirmingExport" :title="__('Export unprinted cards?')" confirm-action="exportUnprinted" cancel-action="cancelExport">
        <p>
            {{ __(':count card(s) will be marked printed and downloaded as a Smart IDesigner import zip. This cannot be undone — exported cards cannot be exported again.', ['count' => $this->unprintedCount()]) }}
        </p>
    </x-confirm-dialog>
</div>
