<?php

use App\Models\Person;
use App\Services\PersonIdNumberGenerator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $entity_type = 'natural';

    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public string $suffix = '';

    public string $legal_name = '';

    public string $date_of_birth = '';

    public string $place_of_birth = '';

    public string $gender = '';

    public string $home_address = '';

    public string $mobile_number = '';

    public string $landline_number = '';

    public string $email = '';

    public string $emergency_contact_name = '';

    public string $emergency_contact_number = '';

    public string $emergency_contact_relation = '';

    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('create', Person::class);
    }

    /**
     * Only the Minimal tier is enforced here (architecture §3) — a name for
     * the kind. Everything else is present on the form but optional; the
     * operation that needs more (contactable, cardable) enforces it later,
     * not this one.
     */
    public function create(PersonIdNumberGenerator $ids): void
    {
        $this->authorize('create', Person::class);

        $validated = $this->validate([
            'entity_type' => ['required', Rule::in(['natural', 'company'])],
            'first_name' => [Rule::requiredIf($this->entity_type === 'natural'), 'nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => [Rule::requiredIf($this->entity_type === 'natural'), 'nullable', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:255'],
            'legal_name' => [Rule::requiredIf($this->entity_type === 'company'), 'nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'place_of_birth' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'prefer_not_to_say'])],
            'home_address' => ['nullable', 'string'],
            'mobile_number' => ['nullable', 'string', 'max:255'],
            'landline_number' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_number' => ['nullable', 'string', 'max:255'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $isCompany = $validated['entity_type'] === 'company';

        $attributes = [
            'entity_type' => $validated['entity_type'],
            'first_name' => $isCompany ? null : $validated['first_name'],
            'middle_name' => $isCompany ? null : ($validated['middle_name'] ?: null),
            'last_name' => $isCompany ? null : $validated['last_name'],
            'suffix' => $isCompany ? null : ($validated['suffix'] ?: null),
            'legal_name' => $isCompany ? $validated['legal_name'] : null,
            'date_of_birth' => $validated['date_of_birth'] ?: null,
            'place_of_birth' => $validated['place_of_birth'] ?: null,
            'gender' => $validated['gender'] ?: null,
            'home_address' => $validated['home_address'] ?: null,
            'mobile_number' => $validated['mobile_number'] ?: null,
            'landline_number' => $validated['landline_number'] ?: null,
            'email' => $validated['email'] ?: null,
            'emergency_contact_name' => $validated['emergency_contact_name'] ?: null,
            'emergency_contact_number' => $validated['emergency_contact_number'] ?: null,
            'emergency_contact_relation' => $validated['emergency_contact_relation'] ?: null,
            'notes' => $validated['notes'] ?: null,
        ];

        $person = $ids->createWithUniqueId($attributes);

        app(\App\Services\AuditLogger::class)->log(
            actor: auth()->user(),
            action: 'person_created',
            subject: $person,
            newValue: ['entity_type' => $person->entity_type, 'display_name' => $person->displayName()],
        );

        $this->redirect(route('people.show', $person), navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Create Person') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form wire:submit="create" class="space-y-4">

                    <x-form-field name="entity_type" :label="__('Kind')">
                        <select wire:model.live="entity_type" id="entity_type" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                            <option value="natural">{{ __('Natural person') }}</option>
                            <option value="company">{{ __('Company') }}</option>
                        </select>
                    </x-form-field>

                    @if ($entity_type === 'company')
                        <x-form-field name="legal_name" :label="__('Legal name')">
                            <x-text-input wire:model="legal_name" id="legal_name" class="block mt-1 w-full" type="text" />
                        </x-form-field>
                    @else
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-form-field name="first_name" :label="__('First name')">
                                <x-text-input wire:model="first_name" id="first_name" class="block mt-1 w-full" type="text" />
                            </x-form-field>
                            <x-form-field name="middle_name" :label="__('Middle name')">
                                <x-text-input wire:model="middle_name" id="middle_name" class="block mt-1 w-full" type="text" />
                            </x-form-field>
                            <x-form-field name="last_name" :label="__('Last name')">
                                <x-text-input wire:model="last_name" id="last_name" class="block mt-1 w-full" type="text" />
                            </x-form-field>
                            <x-form-field name="suffix" :label="__('Suffix')">
                                <x-text-input wire:model="suffix" id="suffix" class="block mt-1 w-full" type="text" />
                            </x-form-field>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <x-form-field name="date_of_birth" :label="__('Date of birth')">
                                <x-text-input wire:model="date_of_birth" id="date_of_birth" class="block mt-1 w-full" type="date" />
                            </x-form-field>
                            <x-form-field name="place_of_birth" :label="__('Place of birth')">
                                <x-text-input wire:model="place_of_birth" id="place_of_birth" class="block mt-1 w-full" type="text" />
                            </x-form-field>
                            <x-form-field name="gender" :label="__('Gender')">
                                <select wire:model="gender" id="gender" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                                    <option value="">{{ __('Not collected') }}</option>
                                    <option value="male">{{ __('Male') }}</option>
                                    <option value="female">{{ __('Female') }}</option>
                                    <option value="prefer_not_to_say">{{ __('Prefer not to say') }}</option>
                                </select>
                            </x-form-field>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-form-field name="mobile_number" :label="__('Mobile number')" hint="{{ __('Required to be a primary unit owner.') }}">
                            <x-text-input wire:model="mobile_number" id="mobile_number" class="block mt-1 w-full" type="text" />
                        </x-form-field>
                        <x-form-field name="landline_number" :label="__('Landline number')">
                            <x-text-input wire:model="landline_number" id="landline_number" class="block mt-1 w-full" type="text" />
                        </x-form-field>
                        <x-form-field name="email" :label="__('Email')" hint="{{ __('Required to be a primary unit owner.') }}">
                            <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" />
                        </x-form-field>
                    </div>

                    <x-form-field name="home_address" :label="__('Home address')">
                        <textarea wire:model="home_address" id="home_address" rows="2" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm"></textarea>
                    </x-form-field>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <x-form-field name="emergency_contact_name" :label="__('Emergency contact name')">
                            <x-text-input wire:model="emergency_contact_name" id="emergency_contact_name" class="block mt-1 w-full" type="text" />
                        </x-form-field>
                        <x-form-field name="emergency_contact_number" :label="__('Emergency contact number')">
                            <x-text-input wire:model="emergency_contact_number" id="emergency_contact_number" class="block mt-1 w-full" type="text" />
                        </x-form-field>
                        <x-form-field name="emergency_contact_relation" :label="__('Relation')">
                            <x-text-input wire:model="emergency_contact_relation" id="emergency_contact_relation" class="block mt-1 w-full" type="text" />
                        </x-form-field>
                    </div>

                    <x-form-field name="notes" :label="__('Notes')">
                        <textarea wire:model="notes" id="notes" rows="3" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm"></textarea>
                    </x-form-field>

                    <div class="flex justify-end">
                        <x-primary-button>{{ __('Create') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
