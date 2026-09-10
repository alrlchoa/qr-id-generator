<?php

use App\Services\ReconciliationQueries;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Architecture §14. Superadmin/Admin only, standing screen, strictly
 * read-only — no filters, no pagination, no writes of any kind, including
 * to `audit_logs` (viewing a list is not a business event). Four
 * independent sections, one per query, each row linking to the screen that
 * resolves it. No bulk actions, no counters, no badges (§14's own "Design
 * constraints").
 */
new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('view-reconciliation-dashboard');
    }

    public function with(ReconciliationQueries $queries): array
    {
        return [
            'leasesPastTerm' => $queries->leasesPastTerm(),
            'cardableAndUncarded' => $queries->cardableAndUncarded(),
            'unitsAtCapacity' => $queries->unitsAtCapacity(),
            'integrityIssues' => $queries->primaryOwnerIntegrityIssues(),
            'queries' => $queries,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Reconciliation Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Query A --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-2">
                <h3 class="font-semibold text-gray-800">{{ __('Query A — Leases past their contract end date') }}</h3>
                <p class="text-sm text-gray-500">
                    {{ __('Still open. Either the lease was renewed and the record needs updating, or the tenancy ended and nobody closed it.') }}
                </p>

                <x-data-table>
                    <x-slot name="head">
                        <th class="py-2 pr-4">{{ __('Person') }}</th>
                        <th class="py-2 pr-4">{{ __('Unit') }}</th>
                        <th class="py-2 pr-4">{{ __('Type') }}</th>
                        <th class="py-2 pr-4">{{ __('Contract ended') }}</th>
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($leasesPastTerm as $relationship)
                        <tr class="border-b" wire:key="lease-{{ $relationship->id }}">
                            <td class="py-2 pr-4">{{ $relationship->person->displayName() }}</td>
                            <td class="py-2 pr-4 font-mono">{{ $relationship->unit->unitCode() }}</td>
                            <td class="py-2 pr-4">{{ ucfirst($relationship->type) }}</td>
                            <td class="py-2 pr-4">{{ $relationship->contract_end_date->format('Y-m-d') }}</td>
                            <td class="py-2">
                                <a href="{{ route('units.show', $relationship->unit) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                    {{ __('Resolve') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="5" />
                    @endforelse
                </x-data-table>
            </div>

            {{-- Query B --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-2">
                <h3 class="font-semibold text-gray-800">{{ __('Query B — Persons who could be carded today and are not') }}</h3>
                <p class="text-sm text-gray-500">
                    {{ __('Natural persons at the cardable tier, with an active owner/tenant relationship and no active card. Companies and below-cardable people are excluded — neither is a real gap.') }}
                </p>

                <x-data-table>
                    <x-slot name="head">
                        <th class="py-2 pr-4">{{ __('Person') }}</th>
                        <th class="py-2 pr-4">{{ __('ID number') }}</th>
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($cardableAndUncarded as $person)
                        <tr class="border-b" wire:key="uncarded-{{ $person->id }}">
                            <td class="py-2 pr-4">{{ $person->displayName() }}</td>
                            <td class="py-2 pr-4 font-mono">{{ $person->user_id_number }}</td>
                            <td class="py-2 space-x-3">
                                <a href="{{ route('people.show', $person) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                    {{ __('View') }}
                                </a>
                                @can('create', \App\Models\IdCard::class)
                                    <a href="{{ route('id-cards.issue', ['person' => $person->user_id_number]) }}" wire:navigate class="underline text-sm text-indigo-600 hover:text-indigo-900">
                                        {{ __('Issue') }}
                                    </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="3" />
                    @endforelse
                </x-data-table>
            </div>

            {{-- Query C --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-2">
                <h3 class="font-semibold text-gray-800">{{ __('Query C — Units with all six occupant slots taken') }}</h3>
                <p class="text-sm text-gray-500">
                    {{ __('Informational — the primary owner\'s reserved slot is not part of this count. Surfaced before an admin hits the capacity limit mid-transaction.') }}
                </p>

                <x-data-table>
                    <x-slot name="head">
                        <th class="py-2 pr-4">{{ __('Unit') }}</th>
                        <th class="py-2 pr-4">{{ __('Primary owner') }}</th>
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($unitsAtCapacity as $unit)
                        <tr class="border-b" wire:key="capacity-{{ $unit->id }}">
                            <td class="py-2 pr-4 font-mono">{{ $unit->unitCode() }}</td>
                            <td class="py-2 pr-4">{{ $unit->primaryOwnerRelationship()?->person?->displayName() ?? __('— none —') }}</td>
                            <td class="py-2">
                                <a href="{{ route('units.show', $unit) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                    {{ __('View') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="3" />
                    @endforelse
                </x-data-table>
            </div>

            {{-- Query D --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-2">
                <h3 class="font-semibold text-gray-800">{{ __('Query D — Units whose active primary-owner count is not exactly one') }}</h3>
                <p class="text-sm text-gray-500">
                    {{ __('An integrity canary, expected permanently empty. Non-empty means something is wrong with the system, not with the data entry.') }}
                </p>

                <x-data-table>
                    <x-slot name="head">
                        <th class="py-2 pr-4">{{ __('Unit') }}</th>
                        <th class="py-2 pr-4">{{ __('Candidates') }}</th>
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($integrityIssues as $unit)
                        <tr class="border-b" wire:key="integrity-{{ $unit->id }}">
                            <td class="py-2 pr-4 font-mono">{{ $unit->unitCode() }}</td>
                            <td class="py-2 pr-4">
                                @php $candidates = $queries->primaryOwnerCandidates($unit); @endphp
                                @forelse ($candidates as $candidate)
                                    <div>{{ $candidate->person->displayName() }} <span class="text-gray-500">({{ __('since') }} {{ $candidate->start_date->format('Y-m-d') }})</span></div>
                                @empty
                                    <span class="text-gray-500">{{ __('none') }}</span>
                                @endforelse
                            </td>
                            <td class="py-2">
                                <a href="{{ route('units.show', $unit) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                    {{ __('Resolve') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="3" />
                    @endforelse
                </x-data-table>
            </div>
        </div>
    </div>
</div>
