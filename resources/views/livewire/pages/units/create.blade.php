<?php

use App\Models\Person;
use App\Models\Unit;
use App\Services\UnitLifecycleManager;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public ?string $building_code = '';

    public string $floor_code = '';

    public string $unit_number = '';

    public string $start_date = '';

    /** 'existing' | 'new' */
    public string $ownerMode = 'existing';

    public string $existing_owner_id_number = '';

    public string $new_owner_entity_type = 'natural';

    public string $new_owner_first_name = '';

    public string $new_owner_last_name = '';

    public string $new_owner_legal_name = '';

    public string $new_owner_mobile_number = '';

    public string $new_owner_email = '';

    /** @var array<int, array{id_number: string, label: string}> */
    public array $availableOwners = [];

    public function mount(): void
    {
        $this->authorize('create', Unit::class);

        $this->start_date = now()->format('Y-m-d');
        $this->availableOwners = $this->loadAvailableOwners();
    }

    /**
     * Every contactable-tier person (architecture §3) — the tier a primary
     * owner must already satisfy — natural or company, formatted for the
     * picker as "ID number - Name".
     *
     * @return array<int, array{id_number: string, label: string}>
     */
    private function loadAvailableOwners(): array
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

    public function create(UnitLifecycleManager $units): void
    {
        $this->authorize('create', Unit::class);

        $validated = $this->validate([
            'building_code' => ['nullable', 'string', 'max:1'],
            'floor_code' => ['required', 'string', 'max:2'],
            'unit_number' => ['required', 'string', 'max:2'],
            'start_date' => ['required', 'date'],
            'ownerMode' => ['required', Rule::in(['existing', 'new'])],
            'existing_owner_id_number' => [Rule::requiredIf($this->ownerMode === 'existing'), 'nullable', 'string'],
            'new_owner_entity_type' => [Rule::requiredIf($this->ownerMode === 'new'), 'nullable', Rule::in(['natural', 'company'])],
            'new_owner_first_name' => [Rule::requiredIf(fn () => $this->ownerMode === 'new' && $this->new_owner_entity_type === 'natural'), 'nullable', 'string'],
            'new_owner_last_name' => [Rule::requiredIf(fn () => $this->ownerMode === 'new' && $this->new_owner_entity_type === 'natural'), 'nullable', 'string'],
            'new_owner_legal_name' => [Rule::requiredIf(fn () => $this->ownerMode === 'new' && $this->new_owner_entity_type === 'company'), 'nullable', 'string'],
            'new_owner_mobile_number' => [Rule::requiredIf($this->ownerMode === 'new'), 'nullable', 'string'],
            'new_owner_email' => [Rule::requiredIf($this->ownerMode === 'new'), 'nullable', 'email'],
        ]);

        if ($this->ownerMode === 'existing') {
            $owner = Person::where('user_id_number', $validated['existing_owner_id_number'])->first();

            if (! $owner) {
                $this->addError('existing_owner_id_number', __('No person with that ID number was found.'));

                return;
            }

            $primaryOwner = ['person_id' => $owner->id];
        } else {
            $isCompany = $validated['new_owner_entity_type'] === 'company';

            $primaryOwner = ['new' => [
                'entity_type' => $validated['new_owner_entity_type'],
                'first_name' => $isCompany ? null : $validated['new_owner_first_name'],
                'last_name' => $isCompany ? null : $validated['new_owner_last_name'],
                'legal_name' => $isCompany ? $validated['new_owner_legal_name'] : null,
                'mobile_number' => $validated['new_owner_mobile_number'],
                'email' => $validated['new_owner_email'],
            ]];
        }

        try {
            $result = $units->createUnit($this->user(), [
                'building_code' => $validated['building_code'] ?: null,
                'floor_code' => $validated['floor_code'],
                'unit_number' => $validated['unit_number'],
            ], $primaryOwner, $validated['start_date']);
        } catch (\App\Exceptions\PrimaryOwnerInvariantException $e) {
            $this->addError('ownerMode', $e->getMessage());

            return;
        }

        $this->redirect(route('units.show', $result['unit']), navigate: true);
    }

    public function user(): \App\Models\User
    {
        return auth()->user();
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Create Unit') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form wire:submit="create" class="space-y-6">

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <x-form-field name="building_code" :label="__('Building code')" hint="{{ __('Single letter, optional.') }}">
                            <x-text-input wire:model="building_code" id="building_code" class="block mt-1 w-full" type="text" maxlength="1" />
                        </x-form-field>
                        <x-form-field name="floor_code" :label="__('Floor code')">
                            <x-text-input wire:model="floor_code" id="floor_code" class="block mt-1 w-full" type="text" maxlength="2" />
                        </x-form-field>
                        <x-form-field name="unit_number" :label="__('Unit number')">
                            <x-text-input wire:model="unit_number" id="unit_number" class="block mt-1 w-full" type="text" maxlength="2" />
                        </x-form-field>
                    </div>

                    <x-form-field name="start_date" :label="__('Ownership start date')">
                        <x-text-input wire:model="start_date" id="start_date" class="block mt-1 w-full" type="date" />
                    </x-form-field>

                    <div class="border-t pt-4">
                        <h3 class="text-sm font-semibold text-gray-700 mb-2">{{ __('Primary owner') }}</h3>
                        <p class="text-xs text-gray-500 mb-4">{{ __('A unit is never created without one — this is not optional.') }}</p>

                        <x-form-field name="ownerMode" :label="__('Owner source')">
                            <select wire:model.live="ownerMode" id="ownerMode" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                <option value="existing">{{ __('Select an existing person') }}</option>
                                <option value="new">{{ __('Create a new person') }}</option>
                            </select>
                        </x-form-field>
                        @error('ownerMode')
                            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                        @enderror

                        @if ($ownerMode === 'existing')
                            <x-person-picker name="existing_owner_id_number" :options="$availableOwners" :label="__('Owner')" />
                        @else
                            <x-form-field name="new_owner_entity_type" :label="__('Kind')">
                                <select wire:model.live="new_owner_entity_type" id="new_owner_entity_type" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="natural">{{ __('Natural person') }}</option>
                                    <option value="company">{{ __('Company') }}</option>
                                </select>
                            </x-form-field>

                            @if ($new_owner_entity_type === 'company')
                                <x-form-field name="new_owner_legal_name" :label="__('Legal name')">
                                    <x-text-input wire:model="new_owner_legal_name" id="new_owner_legal_name" class="block mt-1 w-full" type="text" />
                                </x-form-field>
                            @else
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <x-form-field name="new_owner_first_name" :label="__('First name')">
                                        <x-text-input wire:model="new_owner_first_name" id="new_owner_first_name" class="block mt-1 w-full" type="text" />
                                    </x-form-field>
                                    <x-form-field name="new_owner_last_name" :label="__('Last name')">
                                        <x-text-input wire:model="new_owner_last_name" id="new_owner_last_name" class="block mt-1 w-full" type="text" />
                                    </x-form-field>
                                </div>
                            @endif

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <x-form-field name="new_owner_mobile_number" :label="__('Mobile number')">
                                    <x-text-input wire:model="new_owner_mobile_number" id="new_owner_mobile_number" class="block mt-1 w-full" type="text" />
                                </x-form-field>
                                <x-form-field name="new_owner_email" :label="__('Email')">
                                    <x-text-input wire:model="new_owner_email" id="new_owner_email" class="block mt-1 w-full" type="email" />
                                </x-form-field>
                            </div>
                        @endif
                    </div>

                    <div class="flex justify-end">
                        <x-primary-button>{{ __('Create') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
