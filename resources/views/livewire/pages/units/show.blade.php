<?php

use App\Exceptions\CardIssuanceRefusedException;
use App\Exceptions\PrimaryOwnerInvariantException;
use App\Exceptions\UnitAtCapacityException;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Models\Unit;
use App\Services\IssuanceManager;
use App\Services\RelationshipManager;
use App\Services\UnitDeletionManager;
use App\Services\UnitLifecycleManager;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public Unit $unit;

    // Open relationship form
    public string $open_person_id_number = '';

    public string $open_type = 'tenant';

    public string $open_start_date = '';

    public string $open_contract_end_date = '';

    /** @var array<int, array{id_number: string, label: string}> */
    public array $openRelationshipOptions = [];

    /** Relationships table: hidden by default (rule 4 — activity is `ended_at IS NULL`), revealed on request. */
    public bool $showEndedRelationships = false;

    // Promote form
    public string $promote_relationship_id = '';

    // Transfer form
    public string $transferMode = 'existing';

    public string $transfer_existing_id_number = '';

    /** @var array<int, array{id_number: string, label: string}> */
    public array $transferOwnerOptions = [];

    public string $transfer_new_entity_type = 'natural';

    public string $transfer_new_first_name = '';

    public string $transfer_new_last_name = '';

    public string $transfer_new_legal_name = '';

    public string $transfer_new_mobile_number = '';

    public string $transfer_new_email = '';

    public string $transfer_start_date = '';

    // Restore form
    public string $restore_person_id_number = '';

    public string $restore_start_date = '';

    // Close-relationship confirmation (§5.3)
    public int $closingRelationshipId = 0;

    /** @var array<int, array{control_number: string, type: string}> */
    public array $closePreviewCards = [];

    /** Set after a close whose person still has another active relationship elsewhere — offers the reissue §5.3 names. */
    public ?int $reissueOfferPersonId = null;

    public function mount(Unit $unit): void
    {
        $this->unit = $unit;
        $this->authorize('view', $this->unit);

        $today = now()->format('Y-m-d');
        $this->open_start_date = $today;
        $this->transfer_start_date = $today;
        $this->restore_start_date = $today;

        $this->openRelationshipOptions = $this->loadAllPersons();
        $this->transferOwnerOptions = $this->loadContactablePersons();
    }

    /**
     * Every person, for the Open Relationship picker — opening a co-owner
     * or tenant relationship carries no tier requirement of its own
     * (architecture §3; only issuance later cares about tier). A company
     * picked for `type = 'tenant'` still gets refused server-side, the same
     * as it always has — this list isn't filtered by the currently-selected
     * type, since the picker and the type <select> are independent fields.
     *
     * @return array<int, array{id_number: string, label: string}>
     */
    private function loadAllPersons(): array
    {
        return Person::query()
            ->get()
            ->map(fn (Person $person) => [
                'id_number' => $person->user_id_number,
                'label' => "{$person->user_id_number} - {$person->displayName()}",
            ])
            ->values()
            ->all();
    }

    /**
     * Every contactable-tier person — the tier a primary owner must already
     * satisfy (architecture §3). Same query Create Unit's own picker uses.
     *
     * @return array<int, array{id_number: string, label: string}>
     */
    private function loadContactablePersons(): array
    {
        return Person::query()
            ->whereNotNull('mobile_number')
            ->whereNotNull('email')
            ->get()
            ->map(fn (Person $person) => [
                'id_number' => $person->user_id_number,
                'label' => "{$person->user_id_number} - {$person->displayName()}",
            ])
            ->values()
            ->all();
    }

    public function with(): array
    {
        $relationshipsQuery = $this->unit->relationships()->with('person');

        if (! $this->showEndedRelationships) {
            $relationshipsQuery->whereNull('ended_at');
        }

        // Primary owner(s) first, then everyone else alphabetically — the
        // same naturals-before-companies, last-name-then-legal-name key the
        // People index already sorts by (`0|last_name|first_name` vs
        // `1|legal_name`), just built in PHP over an already-loaded
        // collection rather than as SQL: a unit never holds more than a
        // handful of relationships, so a second `ORDER BY` expression isn't
        // worth it here. sortBy()/sortByDesc() are stable, so sorting by
        // name first and `is_primary_owner` second groups the primary
        // owner(s) at the top without disturbing the name order beneath.
        $relationships = $relationshipsQuery->get()
            ->sortBy(fn (PersonUnitRelationship $r) => $r->person->isCompany()
                ? "1|{$r->person->legal_name}"
                : "0|{$r->person->last_name}|{$r->person->first_name}")
            ->sortByDesc('is_primary_owner')
            ->values();

        return [
            'relationships' => $relationships,
            'primaryOwnerRelationship' => $this->unit->primaryOwnerRelationship(),
            'coOwnerRelationships' => $this->unit->activeRelationships()->where('type', 'owner')->where('is_primary_owner', false)->with('person')->get(),
        ];
    }

    public function openRelationship(RelationshipManager $relationships): void
    {
        $this->authorize('create', PersonUnitRelationship::class);

        $validated = $this->validate([
            'open_person_id_number' => ['required', 'string'],
            'open_type' => ['required', Rule::in(['owner', 'tenant'])],
            'open_start_date' => ['required', 'date'],
            'open_contract_end_date' => ['nullable', 'date'],
        ], [], [], 'openRelationship');

        $person = Person::where('user_id_number', $validated['open_person_id_number'])->first();

        if (! $person) {
            $this->addError('open_person_id_number', __('No person with that ID number was found.'));

            return;
        }

        try {
            $relationships->openRelationship(auth()->user(), $person, $this->unit, $validated['open_type'], $validated['open_start_date'], $validated['open_contract_end_date'] ?: null);
        } catch (\InvalidArgumentException $e) {
            $this->addError('open_type', $e->getMessage());

            return;
        }

        $this->reset('open_person_id_number', 'open_contract_end_date');
        session()->flash('status', __('Relationship opened.'));
    }

    /**
     * Stages the close and previews what it will do to cards, per §5.3's
     * "the confirmation screen names them first" — nothing closes yet.
     * When closing this relationship would expire no card, closing has no
     * consequence worth a modal for, so it proceeds immediately instead.
     */
    public function stageCloseRelationship(int $relationshipId, RelationshipManager $relationships): void
    {
        $relationship = PersonUnitRelationship::findOrFail($relationshipId);
        $this->authorize('update', $relationship);

        $affected = $relationships->cardsAffectedByClosing($relationship);

        if ($affected->isEmpty()) {
            $this->closeRelationshipNow($relationshipId, $relationships);

            return;
        }

        $this->closingRelationshipId = $relationshipId;
        $this->closePreviewCards = $affected->map(fn ($c) => ['control_number' => $c->control_number, 'type' => $c->type])->all();
        $this->dispatch('open-modal', 'close-relationship');
    }

    /** The modal's Confirm button. */
    public function confirmCloseRelationship(RelationshipManager $relationships): void
    {
        $this->closeRelationshipNow($this->closingRelationshipId, $relationships);

        $this->closingRelationshipId = 0;
        $this->closePreviewCards = [];
    }

    private function closeRelationshipNow(int $relationshipId, RelationshipManager $relationships): void
    {
        $relationship = PersonUnitRelationship::findOrFail($relationshipId);
        $this->authorize('update', $relationship);

        $personId = $relationship->person_id;

        try {
            $relationships->closeRelationship(auth()->user(), $relationship);
            session()->flash('status', __('Relationship closed.'));
        } catch (PrimaryOwnerInvariantException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        // §5.3: "where the person retains another active relationship... the
        // screen states that a replacement is required and offers to issue
        // it in the same flow." Skipping this leaves the person on the
        // reconciliation dashboard's Query B until someone issues one.
        $stillEntitled = PersonUnitRelationship::where('person_id', $personId)
            ->whereNull('ended_at')
            ->whereIn('type', ['owner', 'tenant'])
            ->exists();

        $this->reissueOfferPersonId = $stillEntitled ? $personId : null;
    }

    /** Offered after a close that left the person still entitled elsewhere — resolved by §5.1's own tie-breaker, not chosen here. */
    public function issueOfferedReplacement(IssuanceManager $issuance): void
    {
        if ($this->reissueOfferPersonId === null) {
            return;
        }

        $person = Person::findOrFail($this->reissueOfferPersonId);

        try {
            $card = $issuance->issueOwnerOrTenantCard(auth()->user(), $person);
            session()->flash('status', __('Relationship closed. New card #:number issued for :name.', ['number' => $card->control_number, 'name' => $person->displayName()]));
        } catch (CardIssuanceRefusedException|UnitAtCapacityException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->reissueOfferPersonId = null;
    }

    public function promote(UnitLifecycleManager $units): void
    {
        $this->authorize('update', $this->unit);

        $incoming = PersonUnitRelationship::findOrFail($this->promote_relationship_id);

        try {
            $units->promotePrimaryOwner(auth()->user(), $this->unit, $incoming);
            session()->flash('status', __('Primary owner promoted.'));
        } catch (PrimaryOwnerInvariantException|UnitAtCapacityException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->reset('promote_relationship_id');
    }

    public function transfer(UnitLifecycleManager $units): void
    {
        $this->authorize('update', $this->unit);

        $validated = $this->validate([
            'transferMode' => ['required', Rule::in(['existing', 'new'])],
            'transfer_existing_id_number' => [Rule::requiredIf($this->transferMode === 'existing'), 'nullable', 'string'],
            'transfer_new_entity_type' => [Rule::requiredIf($this->transferMode === 'new'), 'nullable', Rule::in(['natural', 'company'])],
            'transfer_new_first_name' => [Rule::requiredIf(fn () => $this->transferMode === 'new' && $this->transfer_new_entity_type === 'natural'), 'nullable', 'string'],
            'transfer_new_last_name' => [Rule::requiredIf(fn () => $this->transferMode === 'new' && $this->transfer_new_entity_type === 'natural'), 'nullable', 'string'],
            'transfer_new_legal_name' => [Rule::requiredIf(fn () => $this->transferMode === 'new' && $this->transfer_new_entity_type === 'company'), 'nullable', 'string'],
            'transfer_new_mobile_number' => [Rule::requiredIf($this->transferMode === 'new'), 'nullable', 'string'],
            'transfer_new_email' => [Rule::requiredIf($this->transferMode === 'new'), 'nullable', 'email'],
            'transfer_start_date' => ['required', 'date'],
        ], [], [], 'transfer');

        $outgoing = $this->unit->primaryOwnerRelationship();

        if ($outgoing === null) {
            session()->flash('error', __('This unit has no active primary owner to transfer from.'));

            return;
        }

        if ($this->transferMode === 'existing') {
            $incomingPerson = Person::where('user_id_number', $validated['transfer_existing_id_number'])->first();

            if (! $incomingPerson) {
                $this->addError('transfer_existing_id_number', __('No person with that ID number was found.'));

                return;
            }

            $party = ['person_id' => $incomingPerson->id];
        } else {
            $isCompany = $validated['transfer_new_entity_type'] === 'company';

            $party = ['new' => [
                'entity_type' => $validated['transfer_new_entity_type'],
                'first_name' => $isCompany ? null : $validated['transfer_new_first_name'],
                'last_name' => $isCompany ? null : $validated['transfer_new_last_name'],
                'legal_name' => $isCompany ? $validated['transfer_new_legal_name'] : null,
                'mobile_number' => $validated['transfer_new_mobile_number'],
                'email' => $validated['transfer_new_email'],
            ]];
        }

        try {
            $units->transferPrimaryOwnership(auth()->user(), $this->unit, $outgoing, $party, $validated['transfer_start_date']);
            session()->flash('status', __('Primary ownership transferred.'));
        } catch (PrimaryOwnerInvariantException|UnitAtCapacityException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->reset('transfer_existing_id_number', 'transfer_new_first_name', 'transfer_new_last_name', 'transfer_new_legal_name', 'transfer_new_mobile_number', 'transfer_new_email');
    }

    public function delete(UnitDeletionManager $units): void
    {
        $this->authorize('delete', $this->unit);

        try {
            $units->delete(auth()->user(), $this->unit);
            $this->redirect(route('units.index'), navigate: true);
        } catch (\App\Exceptions\DeletionBlockedException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function restore(UnitDeletionManager $units, UnitLifecycleManager $lifecycle): void
    {
        $this->authorize('delete', $this->unit);

        $validated = $this->validate([
            'restore_person_id_number' => ['required', 'string'],
            'restore_start_date' => ['required', 'date'],
        ], [], [], 'restore');

        $owner = Person::where('user_id_number', $validated['restore_person_id_number'])->first();

        if (! $owner) {
            $this->addError('restore_person_id_number', __('No person with that ID number was found.'));

            return;
        }

        try {
            $units->restore(auth()->user(), $this->unit, ['person_id' => $owner->id], $validated['restore_start_date'], $lifecycle);
        } catch (PrimaryOwnerInvariantException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->unit = Unit::withTrashed()->findOrFail($this->unit->id);
        session()->flash('status', __('Unit restored.'));
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Unit :code', ['code' => $unit->unitCode()]) }}
            @if ($unit->trashed())
                <span class="text-sm text-red-600">{{ __('(deleted)') }}</span>
            @endif
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-lg">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="p-4 bg-red-100 text-red-700 rounded-lg">{{ session('error') }}</div>
            @endif

            @if ($reissueOfferPersonId)
                <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg flex items-center justify-between gap-4">
                    <span>{{ __('This person still holds another active relationship — a replacement card can be issued now.') }}</span>
                    <div class="flex gap-2 shrink-0">
                        <x-secondary-button type="button" wire:click="$set('reissueOfferPersonId', null)">{{ __('Not now') }}</x-secondary-button>
                        <x-primary-button type="button" wire:click="issueOfferedReplacement">{{ __('Issue replacement card') }}</x-primary-button>
                    </div>
                </div>
            @endif

            @if ($unit->trashed())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-lg font-medium mb-2">{{ __('Restore this unit') }}</h3>
                    <p class="text-sm text-gray-500 mb-4">{{ __('A deleted unit has no primary owner — restoring requires designating one, the same as creating a unit.') }}</p>

                    <form wire:submit="restore" class="space-y-4 max-w-sm">
                        <x-form-field name="restore_person_id_number" :label="__('New primary owner ID number')">
                            <x-text-input wire:model="restore_person_id_number" id="restore_person_id_number" class="block mt-1 w-full" type="text" />
                        </x-form-field>
                        <x-form-field name="restore_start_date" :label="__('Ownership start date')">
                            <x-text-input wire:model="restore_start_date" id="restore_start_date" class="block mt-1 w-full" type="date" />
                        </x-form-field>
                        <x-primary-button>{{ __('Restore') }}</x-primary-button>
                    </form>
                </div>
            @else
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-lg font-medium mb-2">{{ __('Primary owner') }}</h3>
                    @if ($primaryOwnerRelationship)
                        <p>{{ $primaryOwnerRelationship->person->displayName() }} <span class="text-sm text-gray-500 font-mono">({{ $primaryOwnerRelationship->person->user_id_number }})</span></p>
                    @else
                        <p class="text-red-600">{{ __('No active primary owner — this is an integrity issue.') }}</p>
                    @endif
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg overflow-x-auto">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium">{{ __('Relationships') }}</h3>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" wire:model.live="showEndedRelationships" class="rounded border-gray-300">
                            {{ __('Show ended relationships') }}
                        </label>
                    </div>
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="py-2 pr-4">{{ __('Person') }}</th>
                                <th class="py-2 pr-4">{{ __('Type') }}</th>
                                <th class="py-2 pr-4">{{ __('Primary?') }}</th>
                                <th class="py-2 pr-4">{{ __('Start') }}</th>
                                <th class="py-2 pr-4">{{ __('Status') }}</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($relationships as $relationship)
                                <tr class="border-b" wire:key="rel-{{ $relationship->id }}">
                                    <td class="py-2 pr-4">{{ $relationship->person->displayName() }}</td>
                                    <td class="py-2 pr-4">{{ ucfirst($relationship->type) }}</td>
                                    <td class="py-2 pr-4">{{ $relationship->is_primary_owner ? __('Yes') : __('No') }}</td>
                                    <td class="py-2 pr-4">{{ $relationship->start_date->format('Y-m-d') }}</td>
                                    <td class="py-2 pr-4">{{ $relationship->ended_at ? __('Ended :date', ['date' => $relationship->ended_at->format('Y-m-d')]) : __('Active') }}</td>
                                    <td class="py-2">
                                        @if (is_null($relationship->ended_at) && ! $relationship->is_primary_owner)
                                            <button wire:click="stageCloseRelationship({{ $relationship->id }})" wire:confirm="{{ __('Close this relationship?') }}" type="button" class="underline text-sm text-gray-600 hover:text-gray-900">
                                                {{ __('Close') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-6 text-center text-gray-500">
                                        {{ $showEndedRelationships ? __('No relationships at all.') : __('No active relationships.') }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @can('create', \App\Models\PersonUnitRelationship::class)
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        <h3 class="text-lg font-medium mb-4">{{ __('Open a relationship') }}</h3>
                        <form wire:submit="openRelationship" class="space-y-4 max-w-md">
                            <x-person-picker name="open_person_id_number" :options="$openRelationshipOptions" :label="__('Person')" />
                            <x-form-field name="open_type" :label="__('Type')">
                                <select wire:model="open_type" id="open_type" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="tenant">{{ __('Tenant') }}</option>
                                    <option value="owner">{{ __('Co-owner') }}</option>
                                </select>
                            </x-form-field>
                            <x-form-field name="open_start_date" :label="__('Start date')">
                                <x-text-input wire:model="open_start_date" id="open_start_date" class="block mt-1 w-full" type="date" />
                            </x-form-field>
                            <x-form-field name="open_contract_end_date" :label="__('Contract end date')" hint="{{ __('Informational only — never drives status.') }}">
                                <x-text-input wire:model="open_contract_end_date" id="open_contract_end_date" class="block mt-1 w-full" type="date" />
                            </x-form-field>
                            <x-secondary-button>{{ __('Open relationship') }}</x-secondary-button>
                        </form>
                    </div>
                @endcan

                @if ($coOwnerRelationships->isNotEmpty())
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        <h3 class="text-lg font-medium mb-2">{{ __('Promote a co-owner to primary') }}</h3>
                        <p class="text-sm text-gray-500 mb-4">{{ __('Moves the role between two existing owners. No card is affected.') }}</p>
                        <form wire:submit="promote" class="flex items-end gap-4 max-w-md">
                            <x-form-field name="promote_relationship_id" :label="__('Co-owner')">
                                <select wire:model="promote_relationship_id" id="promote_relationship_id" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">{{ __('Select...') }}</option>
                                    @foreach ($coOwnerRelationships as $co)
                                        <option value="{{ $co->id }}">{{ $co->person->displayName() }}</option>
                                    @endforeach
                                </select>
                            </x-form-field>
                            <x-secondary-button>{{ __('Promote') }}</x-secondary-button>
                        </form>
                    </div>
                @endif

                @can('update', $unit)
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        <h3 class="text-lg font-medium mb-2">{{ __('Transfer primary ownership') }}</h3>
                        <p class="text-sm text-gray-500 mb-4">{{ __('The outgoing owner\'s relationship closes; this is different from promotion.') }}</p>

                        <form wire:submit="transfer" class="space-y-4 max-w-md">
                            <x-form-field name="transferMode" :label="__('New owner source')">
                                <select wire:model.live="transferMode" id="transferMode" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="existing">{{ __('Select an existing person') }}</option>
                                    <option value="new">{{ __('Create a new person') }}</option>
                                </select>
                            </x-form-field>

                            @if ($transferMode === 'existing')
                                <x-person-picker name="transfer_existing_id_number" :options="$transferOwnerOptions" :label="__('New owner')" />
                            @else
                                <x-form-field name="transfer_new_entity_type" :label="__('Kind')">
                                    <select wire:model.live="transfer_new_entity_type" id="transfer_new_entity_type" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                        <option value="natural">{{ __('Natural person') }}</option>
                                        <option value="company">{{ __('Company') }}</option>
                                    </select>
                                </x-form-field>

                                @if ($transfer_new_entity_type === 'company')
                                    <x-form-field name="transfer_new_legal_name" :label="__('Legal name')">
                                        <x-text-input wire:model="transfer_new_legal_name" id="transfer_new_legal_name" class="block mt-1 w-full" type="text" />
                                    </x-form-field>
                                @else
                                    <x-form-field name="transfer_new_first_name" :label="__('First name')">
                                        <x-text-input wire:model="transfer_new_first_name" id="transfer_new_first_name" class="block mt-1 w-full" type="text" />
                                    </x-form-field>
                                    <x-form-field name="transfer_new_last_name" :label="__('Last name')">
                                        <x-text-input wire:model="transfer_new_last_name" id="transfer_new_last_name" class="block mt-1 w-full" type="text" />
                                    </x-form-field>
                                @endif

                                <x-form-field name="transfer_new_mobile_number" :label="__('Mobile number')">
                                    <x-text-input wire:model="transfer_new_mobile_number" id="transfer_new_mobile_number" class="block mt-1 w-full" type="text" />
                                </x-form-field>
                                <x-form-field name="transfer_new_email" :label="__('Email')">
                                    <x-text-input wire:model="transfer_new_email" id="transfer_new_email" class="block mt-1 w-full" type="email" />
                                </x-form-field>
                            @endif

                            <x-form-field name="transfer_start_date" :label="__('Ownership start date')">
                                <x-text-input wire:model="transfer_start_date" id="transfer_start_date" class="block mt-1 w-full" type="date" />
                            </x-form-field>

                            <x-danger-button>{{ __('Transfer ownership') }}</x-danger-button>
                        </form>
                    </div>
                @endcan

                @can('delete', $unit)
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        <h3 class="text-lg font-medium mb-2">{{ __('Delete unit') }}</h3>
                        <p class="text-sm text-gray-500 mb-4">{{ __('Refused while any relationship other than the primary owner\'s, or any active card, is still live.') }}</p>
                        <button wire:click="delete" wire:confirm="{{ __('Delete this unit?') }}" type="button">
                            <x-danger-button type="button">{{ __('Delete unit') }}</x-danger-button>
                        </button>
                    </div>
                @endcan
            @endif
        </div>
    </div>

    <x-confirm-dialog name="close-relationship" :title="__('Closing this relationship will expire :count card(s)', ['count' => count($closePreviewCards)])" confirmAction="confirmCloseRelationship" :confirmLabel="__('Close and expire')">
        <p class="mb-3">{{ __('The following active cards will be expired — this cannot be undone:') }}</p>
        <ul class="list-disc list-inside space-y-1">
            @foreach ($closePreviewCards as $card)
                <li><span class="font-mono">#{{ $card['control_number'] }}</span> ({{ ucfirst($card['type']) }})</li>
            @endforeach
        </ul>
    </x-confirm-dialog>
</div>
