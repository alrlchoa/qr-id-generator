<?php

use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $actorUsername = '';

    #[Url]
    public string $action = '';

    #[Url]
    public string $subjectType = '';

    #[Url]
    public string $subjectId = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AuditLog::class);
    }

    /**
     * Read-only end to end — this page issues no writes at all, including
     * to audit_logs itself. Reading the trail is not a business event
     * (architecture §14 makes the same point about the reconciliation
     * dashboard): it would be strange for looking at the log to be the one
     * thing that adds to it.
     */
    public function with(): array
    {
        $query = AuditLog::query()->orderByDesc('occurred_at');

        if ($this->actorUsername !== '') {
            $query->whereHas('user', function ($q) {
                $q->where('username', 'like', '%'.$this->actorUsername.'%');
            });
        }

        if ($this->action !== '') {
            $query->where('action', 'like', '%'.$this->action.'%');
        }

        if ($this->subjectType !== '') {
            $query->where('subject_type', $this->subjectType);
        }

        if ($this->subjectId !== '' && ctype_digit($this->subjectId)) {
            $query->where('subject_id', (int) $this->subjectId);
        }

        if ($this->dateFrom !== '') {
            $query->where('occurred_at', '>=', $this->dateFrom.' 00:00:00');
        }

        if ($this->dateTo !== '') {
            $query->where('occurred_at', '<=', $this->dateTo.' 23:59:59');
        }

        return [
            'logs' => $query->paginate(25),
            // Populate the filter dropdowns from what actually appears in
            // the table, rather than a hand-maintained list that drifts
            // out of step with the action vocabulary as it grows.
            'knownActions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'knownSubjectTypes' => AuditLog::query()->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ];
    }

    public function resetFilters(): void
    {
        $this->reset('actorUsername', 'action', 'subjectType', 'subjectId', 'dateFrom', 'dateTo');
        $this->resetPage();
    }

    public function updated($property): void
    {
        if (in_array($property, ['actorUsername', 'action', 'subjectType', 'subjectId', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Audit Log') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="p-4 sm:p-6 bg-white shadow sm:rounded-lg">
                <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div>
                        <x-input-label for="actorUsername" :value="__('Actor')" />
                        <x-text-input wire:model.live.debounce.400ms="actorUsername" id="actorUsername" class="block mt-1 w-full" type="text" placeholder="{{ __('username') }}" />
                    </div>

                    <div>
                        <x-input-label for="action" :value="__('Action')" />
                        <input wire:model.live="action" list="known-actions" id="action" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full" type="text" placeholder="{{ __('any') }}" />
                        <datalist id="known-actions">
                            @foreach ($knownActions as $knownAction)
                                <option value="{{ $knownAction }}"></option>
                            @endforeach
                        </datalist>
                    </div>

                    <div>
                        <x-input-label for="subjectType" :value="__('Subject type')" />
                        <select wire:model.live="subjectType" id="subjectType" class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm block mt-1 w-full">
                            <option value="">{{ __('Any') }}</option>
                            @foreach ($knownSubjectTypes as $type)
                                <option value="{{ $type }}">{{ class_basename($type) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <x-input-label for="subjectId" :value="__('Subject #')" />
                        <x-text-input wire:model.live.debounce.400ms="subjectId" id="subjectId" class="block mt-1 w-full" type="text" inputmode="numeric" placeholder="{{ __('any') }}" />
                    </div>

                    <div>
                        <x-input-label for="dateFrom" :value="__('From')" />
                        <x-text-input wire:model.live="dateFrom" id="dateFrom" class="block mt-1 w-full" type="date" />
                    </div>

                    <div>
                        <x-input-label for="dateTo" :value="__('To')" />
                        <x-text-input wire:model.live="dateTo" id="dateTo" class="block mt-1 w-full" type="date" />
                    </div>
                </div>

                <div class="mt-4">
                    <button wire:click="resetFilters" type="button" class="underline text-sm text-gray-600 hover:text-gray-900">
                        {{ __('Clear filters') }}
                    </button>
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="py-2 pr-4">{{ __('Occurred') }}</th>
                            <th class="py-2 pr-4">{{ __('Actor') }}</th>
                            <th class="py-2 pr-4">{{ __('Action') }}</th>
                            <th class="py-2 pr-4">{{ __('Subject') }}</th>
                            <th class="py-2 pr-4">{{ __('Changes') }}</th>
                            <th class="py-2">{{ __('IP') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr class="border-b align-top" wire:key="log-{{ $log->id }}">
                                <td class="py-2 pr-4 whitespace-nowrap">{{ $log->occurred_at->format('Y-m-d H:i:s') }}</td>
                                <td class="py-2 pr-4">
                                    {{ $log->user?->username ?? '—' }}
                                    <span class="text-gray-500">({{ $log->user_role }})</span>
                                </td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $log->action }}</td>
                                <td class="py-2 pr-4">{{ $log->subjectLabel() }}</td>
                                <td class="py-2 pr-4 font-mono text-xs">
                                    @if ($log->previous_value)
                                        <div>{{ __('was') }}: {{ json_encode($log->previous_value) }}</div>
                                    @endif
                                    @if ($log->new_value)
                                        <div>{{ __('now') }}: {{ json_encode($log->new_value) }}</div>
                                    @endif
                                </td>
                                <td class="py-2 whitespace-nowrap">{{ $log->ip_address ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-gray-500">{{ __('No matching audit entries.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">
                    {{ $logs->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
