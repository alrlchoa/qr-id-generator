<?php

use App\Models\Person;
use App\Services\AuditLogger;
use App\Services\PersonPhotoService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public Person $person;

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

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $photo = null;

    public function mount(Person $person): void
    {
        $this->authorize('view', $person);

        $this->person = $person;
        $this->first_name = (string) $person->first_name;
        $this->middle_name = (string) $person->middle_name;
        $this->last_name = (string) $person->last_name;
        $this->suffix = (string) $person->suffix;
        $this->legal_name = (string) $person->legal_name;
        $this->date_of_birth = $person->date_of_birth?->format('Y-m-d') ?? '';
        $this->place_of_birth = (string) $person->place_of_birth;
        $this->gender = (string) $person->gender;
        $this->home_address = (string) $person->home_address;
        $this->mobile_number = (string) $person->mobile_number;
        $this->landline_number = (string) $person->landline_number;
        $this->email = (string) $person->email;
        $this->emergency_contact_name = (string) $person->emergency_contact_name;
        $this->emergency_contact_number = (string) $person->emergency_contact_number;
        $this->emergency_contact_relation = (string) $person->emergency_contact_relation;
        $this->notes = (string) $person->notes;
    }

    /**
     * `entity_type` is immutable after creation (CLAUDE.md rule 35) — this
     * form never offers to change it, and validation here covers only the
     * fields that vary by the record's existing kind.
     */
    public function save(AuditLogger $auditLogger): void
    {
        $this->authorize('update', $this->person);

        $isCompany = $this->person->isCompany();

        $validated = $this->validate([
            'first_name' => [$isCompany ? 'nullable' : 'required', 'nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => [$isCompany ? 'nullable' : 'required', 'nullable', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:255'],
            'legal_name' => [$isCompany ? 'required' : 'nullable', 'nullable', 'string', 'max:255'],
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

        $attributes = [
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

        $changed = array_diff_assoc($attributes, $this->person->only(array_keys($attributes)));

        if ($changed !== []) {
            $previous = $this->person->only(array_keys($changed));

            $this->person->forceFill($attributes)->save();

            $auditLogger->log(
                actor: auth()->user(),
                action: 'person_data_updated',
                subject: $this->person,
                previousValue: $previous,
                newValue: $changed,
            );
        }

        session()->flash('status', __('Saved.'));
    }

    public function delete(\App\Services\PersonDeletionManager $deletions): void
    {
        $this->authorize('delete', $this->person);

        try {
            $deletions->delete(auth()->user(), $this->person);
            $this->redirect(route('people.index'), navigate: true);
        } catch (\App\Exceptions\PrimaryOwnerInvariantException|\App\Exceptions\DeletionBlockedException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function clearStagedPhoto(): void
    {
        $this->photo = null;
    }

    public function uploadPhoto(PersonPhotoService $photos, AuditLogger $auditLogger): void
    {
        $this->authorize('update', $this->person);

        $this->validate([
            'photo' => ['required', 'image', 'max:1024'],
        ]);

        $hadPhotoBefore = (bool) $this->person->photo_path;

        $path = $photos->store($this->person, $this->photo);

        $this->person->forceFill(['photo_path' => $path])->save();

        $auditLogger->log(
            actor: auth()->user(),
            action: 'photo_updated',
            subject: $this->person,
            previousValue: ['had_photo' => $hadPhotoBefore],
            newValue: ['had_photo' => true],
        );

        $this->photo = null;

        session()->flash('status', __('Photo updated.'));
    }

    /**
     * The stored photo's size on disk — after crop/compress, i.e. what's
     * actually being served, not whatever was originally uploaded.
     */
    public function photoSizeLabel(): ?string
    {
        $bytes = app(PersonPhotoService::class)->sizeInBytes($this->person);

        return $bytes === null ? null : \Illuminate\Support\Number::fileSize($bytes, precision: 1);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Person #:id — :name', ['id' => $person->user_id_number, 'name' => $person->displayName()]) }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-100 text-green-800 rounded-lg">{{ session('status') }}</div>
            @endif
            @if (session('error'))
                <div class="p-4 bg-red-100 text-red-700 rounded-lg">{{ session('error') }}</div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <h3 class="text-lg font-medium">{{ __('Photo') }}</h3>

                <div class="flex items-center gap-6">
                    @if ($person->photo_path)
                        <div>
                            <img src="{{ route('people.photo', $person) }}" alt="" class="w-24 h-24 object-cover rounded-md border">
                            <p class="text-xs text-gray-400 mt-1 text-center">{{ $this->photoSizeLabel() }}</p>
                        </div>
                    @else
                        <div class="w-24 h-24 flex items-center justify-center rounded-md border text-xs text-gray-400 text-center">
                            {{ __('No photo') }}
                        </div>
                    @endif

                    @can('update', $person)
                        <form wire:submit="uploadPhoto" class="space-y-2">
                            @if ($photo)
                                <div class="flex items-center gap-4">
                                    <div>
                                        <img src="{{ $photo->temporaryUrl() }}" alt="" class="w-24 h-24 object-cover rounded-md border">
                                        @if ($photo->getSize() !== false)
                                            <p class="text-xs text-gray-400 mt-1 text-center">{{ \Illuminate\Support\Number::fileSize($photo->getSize(), precision: 1) }}</p>
                                        @endif
                                    </div>
                                    <x-secondary-button type="button" wire:click="clearStagedPhoto">{{ __('Clear') }}</x-secondary-button>
                                </div>
                            @else
                                <div class="flex flex-wrap items-start gap-6">
                                    <div>
                                        <x-cropping-file-input name="photo" />
                                        <p class="text-xs text-gray-400 mt-1">{{ __('JPEG or PNG, up to 1MB. Non-square photos open a crop tool.') }}</p>
                                    </div>
                                    <x-camera-capture name="photo" />
                                </div>
                            @endif

                            <x-input-error :messages="$errors->get('photo')" class="mt-2" />
                            <x-secondary-button type="submit">{{ __('Upload photo') }}</x-secondary-button>
                        </form>
                    @endcan
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form wire:submit="save" class="space-y-4">

                    @if ($person->isCompany())
                        <x-form-field name="legal_name" :label="__('Legal name')">
                            <x-text-input wire:model="legal_name" id="legal_name" class="block mt-1 w-full" type="text" :disabled="! auth()->user()->can('update', $person)" />
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
                        <x-form-field name="mobile_number" :label="__('Mobile number')">
                            <x-text-input wire:model="mobile_number" id="mobile_number" class="block mt-1 w-full" type="text" />
                        </x-form-field>
                        <x-form-field name="landline_number" :label="__('Landline number')">
                            <x-text-input wire:model="landline_number" id="landline_number" class="block mt-1 w-full" type="text" />
                        </x-form-field>
                        <x-form-field name="email" :label="__('Email')">
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

                    @can('update', $person)
                        <div class="flex justify-end">
                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                        </div>
                    @endcan
                </form>
            </div>

            @can('delete', $person)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-lg font-medium mb-2">{{ __('Delete') }}</h3>
                    <p class="text-sm text-gray-500 mb-4">{{ __('Refused while this person is a unit\'s primary owner, or holds any active relationship or card.') }}</p>
                    <button wire:click="delete" wire:confirm="{{ __('Delete this person?') }}" type="button">
                        <x-danger-button type="button">{{ __('Delete') }}</x-danger-button>
                    </button>
                </div>
            @endcan
        </div>
    </div>
</div>
