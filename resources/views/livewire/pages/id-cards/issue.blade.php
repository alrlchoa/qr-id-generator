<?php

use App\Exceptions\CardIssuanceRefusedException;
use App\Exceptions\UnitAtCapacityException;
use App\Models\IdCard;
use App\Models\Person;
use App\Models\Unit;
use App\Services\IssuanceManager;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    /** 'owner_tenant' | 'employee' */
    public string $mode = 'owner_tenant';

    /**
     * Prefillable via ?person=<id_number> — the reconciliation dashboard's
     * Query B links here directly for a person it already knows is
     * cardable-tier and un-carded, rather than making the admin look the
     * person up again in the picker.
     */
    #[Url(as: 'person')]
    public string $person_id_number = '';

    public string $unit_override_code = '';

    public string $position = '';

    public string $department = '';

    /** @var array<int, array{id_number: string, label: string}> */
    public array $personOptions = [];

    public function mount(): void
    {
        $this->authorize('create', IdCard::class);
        $this->personOptions = $this->loadPersonOptions();
    }

    /**
     * What the person picker's search box shows when `?person=` prefilled
     * `person_id_number` before any typing — the underlying property is
     * already correct either way, this is purely so the visible text
     * matches it rather than showing an empty box over a filled value.
     */
    public function initialPersonQuery(): string
    {
        $option = collect($this->personOptions)->firstWhere('id_number', $this->person_id_number);

        return $option['label'] ?? '';
    }

    /**
     * Every natural person — companies never hold cards (rule 36), and
     * issuance's own cardable-tier check is what refuses a person below
     * it, with a specific missing-fields message. No point pre-filtering
     * this list that finely; the picker just needs to exclude companies
     * outright, the one refusal no error message would explain well here.
     *
     * @return array<int, array{id_number: string, label: string}>
     */
    private function loadPersonOptions(): array
    {
        return Person::query()
            ->where('entity_type', 'natural')
            ->get()
            ->map(fn (Person $person) => [
                'id_number' => $person->user_id_number,
                'label' => "{$person->user_id_number} - {$person->displayName()}",
            ])
            ->values()
            ->all();
    }

    public function issueOwnerOrTenant(IssuanceManager $issuance): void
    {
        $this->authorize('create', IdCard::class);

        $validated = $this->validate([
            'person_id_number' => ['required', 'string'],
            'unit_override_code' => ['nullable', 'string'],
        ]);

        $person = Person::where('user_id_number', $validated['person_id_number'])->first();

        if (! $person) {
            $this->addError('person_id_number', __('No person with that ID number was found.'));

            return;
        }

        $unitOverride = null;

        if (filled($validated['unit_override_code'])) {
            $unitOverride = $this->resolveUnitByCode($validated['unit_override_code']);

            if ($unitOverride === null) {
                $this->addError('unit_override_code', __('No unit with that code was found.'));

                return;
            }
        }

        try {
            $card = $issuance->issueOwnerOrTenantCard(auth()->user(), $person, $unitOverride);
        } catch (CardIssuanceRefusedException|UnitAtCapacityException|\InvalidArgumentException $e) {
            $this->addError('person_id_number', $e->getMessage());

            return;
        }

        $this->redirect(route('id-cards.show', $card), navigate: true);
    }

    public function issueEmployee(IssuanceManager $issuance): void
    {
        $this->authorize('issueEmployee', IdCard::class);

        $validated = $this->validate([
            'person_id_number' => ['required', 'string'],
            'position' => ['nullable', 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
        ]);

        $person = Person::where('user_id_number', $validated['person_id_number'])->first();

        if (! $person) {
            $this->addError('person_id_number', __('No person with that ID number was found.'));

            return;
        }

        try {
            $card = $issuance->issueEmployeeCard(auth()->user(), $person, $validated['position'] ?: null, $validated['department'] ?: null);
        } catch (CardIssuanceRefusedException $e) {
            $this->addError('person_id_number', $e->getMessage());

            return;
        }

        $this->redirect(route('id-cards.show', $card), navigate: true);
    }

    /**
     * The unit picker here works by code (§3's fixed ABBCC shape), not the
     * person-id-number lookup the person picker uses — a unit has no
     * equivalent identifier field of its own, and the code is what an
     * admin already reads off the Units screen.
     */
    private function resolveUnitByCode(string $code): ?Unit
    {
        return Unit::all()->first(fn (Unit $unit) => $unit->unitCode() === strtoupper(trim($code)));
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Issue an ID') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-6">
                <div class="flex gap-2 border-b pb-4">
                    <button type="button" wire:click="$set('mode', 'owner_tenant')" class="px-3 py-1.5 text-sm rounded-md {{ $mode === 'owner_tenant' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
                        {{ __('Owner / Tenant') }}
                    </button>
                    @can('issueEmployee', \App\Models\IdCard::class)
                        <button type="button" wire:click="$set('mode', 'employee')" class="px-3 py-1.5 text-sm rounded-md {{ $mode === 'employee' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }}">
                            {{ __('Employee') }}
                        </button>
                    @endcan
                </div>

                @if ($mode === 'owner_tenant')
                    <form wire:submit="issueOwnerOrTenant" class="space-y-4">
                        <x-person-picker name="person_id_number" :options="$personOptions" :label="__('Person')" :initial-query="$this->initialPersonQuery()" />

                        <x-form-field name="unit_override_code" :label="__('Unit (optional override)')" hint="{{ __('Leave blank to use the winning relationship automatically (owner outranks tenant; earliest start date). Only relevant for a person with more than one active relationship.') }}">
                            <x-text-input wire:model="unit_override_code" id="unit_override_code" class="block mt-1 w-full" type="text" placeholder="{{ __('e.g. A0101') }}" />
                        </x-form-field>

                        <div class="flex justify-end">
                            <x-primary-button type="submit">{{ __('Issue card') }}</x-primary-button>
                        </div>
                    </form>
                @else
                    <form wire:submit="issueEmployee" class="space-y-4">
                        <x-person-picker name="person_id_number" :options="$personOptions" :label="__('Person')" />

                        <x-form-field name="position" :label="__('Position')">
                            <x-text-input wire:model="position" id="position" class="block mt-1 w-full" type="text" />
                        </x-form-field>

                        <x-form-field name="department" :label="__('Department')">
                            <x-text-input wire:model="department" id="department" class="block mt-1 w-full" type="text" />
                        </x-form-field>

                        <div class="flex justify-end">
                            <x-primary-button type="submit">{{ __('Issue employee card') }}</x-primary-button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
