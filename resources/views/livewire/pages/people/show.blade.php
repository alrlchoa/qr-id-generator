<?php

use App\Exceptions\CardIssuanceRefusedException;
use App\Exceptions\InvalidContractEndDateException;
use App\Exceptions\PrimaryOwnerInvariantException;
use App\Exceptions\UnitAtCapacityException;
use App\Models\Person;
use App\Models\PersonUnitRelationship;
use App\Services\AuditLogger;
use App\Services\IdCardLifecycleManager;
use App\Services\IssuanceManager;
use App\Services\PersonPhotoService;
use App\Services\RelationshipManager;
use Illuminate\Support\Facades\DB;
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

    /**
     * Set once `save()` determines a printed field changed and the person
     * holds one or more active cards — architecture §9.3's confirm-or-cancel
     * gate. Nothing is persisted yet while this is true; `confirmReissue()`
     * is the only path that commits.
     */
    public bool $confirmingReissue = false;

    /** @var array<int, array{control_number: string, type: string, unit_code: string|null}> */
    public array $reissuePreviewCards = [];

    /** Relationships table: hidden by default (rule 4 — activity is `ended_at IS NULL`), revealed on request. */
    public bool $showEndedRelationships = false;

    /** Close-relationship confirmation (§5.3), same shape as the unit show page's. */
    public int $closingRelationshipId = 0;

    /** @var array<int, array{control_number: string, type: string}> */
    public array $closePreviewCards = [];

    /** Set after a close that leaves this person still entitled elsewhere — offers the reissue §5.3 names. */
    public bool $reissueOffered = false;

    // Edit contract end date (architecture §14 Query A's own resolution
    // action: "extend contract_end_date" — paperwork, not activity, rule 4)
    public int $editingRelationshipId = 0;

    public string $editContractEndDate = '';

    public function mount(Person $person): void
    {
        $this->authorize('view', $person);

        $this->person = $person;
        $this->hydrateFieldsFromPerson();
    }

    private function hydrateFieldsFromPerson(): void
    {
        $person = $this->person;

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
     * Discards every unsaved browser-side change — form fields and any
     * staged (not-yet-saved) photo — back to what the server actually has.
     * Re-fetches rather than reusing the in-memory `$this->person`, so
     * "what's currently saved" means the real row, not just whatever was
     * loaded when the page opened.
     */
    public function resetForm(): void
    {
        $this->person = $this->person->fresh();
        $this->hydrateFieldsFromPerson();
        $this->photo = null;
        $this->confirmingReissue = false;
        $this->reissuePreviewCards = [];
        $this->resetErrorBag();
        session()->forget(['status', 'error']);
        $this->dispatch('close-modal', 'mandatory-reissue');
    }

    /**
     * `entity_type` is immutable after creation (CLAUDE.md rule 35) — this
     * form never offers to change it, and validation here covers only the
     * fields that vary by the record's existing kind.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [attributes, changed]
     */
    private function validateAndDiff(): array
    {
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
            'photo' => ['nullable', 'image', 'max:1024'],
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

        return [$attributes, $changed];
    }

    /**
     * The printed-field list, restricted to what this screen can actually
     * edit (CLAUDE.md rule 10) — `legal_name` is deliberately absent
     * (architecture §9.3: a company holds no cards, so renaming one
     * triggers nothing).
     *
     * @param  array<string, mixed>  $changed
     */
    private function printedFieldsChanged(array $changed): bool
    {
        return array_intersect(array_keys($changed), ['first_name', 'middle_name', 'last_name', 'suffix']) !== [];
    }

    /**
     * One save action for the whole record, photo included — there is no
     * separate "Upload photo" submit; a staged photo does nothing server-
     * side until this runs, exactly like every other field on this form.
     *
     * Architecture §9.3: changing a printed field (a name field or the
     * photo) while the person holds one or more active cards is not a
     * plain save — it names every affected card and waits for
     * `confirmReissue()`. Everything else still saves immediately; there is
     * no card consequence to gate on.
     */
    public function save(PersonPhotoService $photos, AuditLogger $auditLogger, IdCardLifecycleManager $cards): void
    {
        $this->authorize('update', $this->person);

        [$attributes, $changed] = $this->validateAndDiff();

        $activeCards = $this->person->idCards()->where('status', 'active')->orderBy('unit_id')->get();

        if (($this->printedFieldsChanged($changed) || $this->photo) && $activeCards->isNotEmpty()) {
            $this->reissuePreviewCards = $activeCards->map(fn ($c) => [
                'control_number' => $c->control_number,
                'type' => $c->type,
                'unit_code' => $c->unit?->unitCode(),
            ])->all();
            $this->confirmingReissue = true;
            $this->dispatch('open-modal', 'mandatory-reissue');

            return;
        }

        $this->commitSave($attributes, $changed, $photos, $auditLogger, $cards, reissue: false);
        session()->flash('status', __('Saved.'));
    }

    /**
     * The modal's Confirm button — the only path that can commit once
     * `save()` has gated on a printed-field change with active cards.
     * There is no third option (rule 11): Cancel (the modal's own button)
     * closes it with nothing persisted; this re-validates the same form
     * state and commits the data change and every reissue atomically.
     */
    public function confirmReissue(PersonPhotoService $photos, AuditLogger $auditLogger, IdCardLifecycleManager $cards): void
    {
        $this->authorize('update', $this->person);

        [$attributes, $changed] = $this->validateAndDiff();

        $this->commitSave($attributes, $changed, $photos, $auditLogger, $cards, reissue: true);

        $this->confirmingReissue = false;
        $this->reissuePreviewCards = [];
        session()->flash('status', __('Saved and reissued.'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $changed
     */
    private function commitSave(array $attributes, array $changed, PersonPhotoService $photos, AuditLogger $auditLogger, IdCardLifecycleManager $cards, bool $reissue): void
    {
        $photoWasStaged = (bool) $this->photo;

        DB::transaction(function () use ($attributes, $changed, $photos, $auditLogger, $cards, $reissue, $photoWasStaged) {
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

            if ($this->photo) {
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
            }

            if ($reissue) {
                // Photo takes priority as the more visually distinct reason
                // when both a name field and the photo changed in the same
                // save — an arbitrary but harmless tie-break; the audit
                // trail on both person_data_updated/photo_updated above
                // already records exactly what changed either way.
                $reason = $photoWasStaged ? 'photo_change' : 'name_change';

                $activeCards = $this->person->idCards()->where('status', 'active')->orderBy('unit_id')->get();

                foreach ($activeCards as $card) {
                    $cards->replace(auth()->user(), $card, oldStatus: 'replaced', replacementReason: $reason);
                }
            }

            $this->photo = null;
        });
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

    /**
     * The stored photo's size on disk — after crop/compress, i.e. what's
     * actually being served, not whatever was originally uploaded.
     */
    public function photoSizeLabel(): ?string
    {
        $bytes = app(PersonPhotoService::class)->sizeInBytes($this->person);

        return $bytes === null ? null : \Illuminate\Support\Number::fileSize($bytes, precision: 1);
    }

    /**
     * Every unit this person is (or was) connected to, active-only by
     * default. Primary-owner relationships sort first, then the rest by
     * unit code — there's no "name" to sort by here the way the unit show
     * page's mirror-image table does, since every row is the same person.
     */
    public function with(): array
    {
        $query = $this->person->relationships()->with('unit');

        if (! $this->showEndedRelationships) {
            $query->whereNull('ended_at');
        }

        $relationships = $query->get()
            ->sortBy(fn (PersonUnitRelationship $r) => $r->unit->unitCode())
            ->sortByDesc('is_primary_owner')
            ->values();

        return ['relationships' => $relationships];
    }

    /**
     * Stages the close and previews what it will do to cards, per §5.3's
     * "the confirmation screen names them first" — nothing closes yet.
     * Identical shape to the unit show page's own version of this method.
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

        try {
            $relationships->closeRelationship(auth()->user(), $relationship);
            session()->flash('status', __('Relationship closed.'));
        } catch (PrimaryOwnerInvariantException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        // §5.3: offer a replacement card immediately when this person still
        // holds another active owner/tenant relationship elsewhere — same
        // reasoning as the unit show page's version of this check.
        $this->reissueOffered = PersonUnitRelationship::where('person_id', $this->person->id)
            ->whereNull('ended_at')
            ->whereIn('type', ['owner', 'tenant'])
            ->exists();
    }

    /** Offered after a close that left this person still entitled elsewhere — resolved by §5.1's own tie-breaker, not chosen here. */
    public function issueOfferedReplacement(IssuanceManager $issuance): void
    {
        if (! $this->reissueOffered) {
            return;
        }

        try {
            $card = $issuance->issueOwnerOrTenantCard(auth()->user(), $this->person);
            session()->flash('status', __('Relationship closed. New card #:number issued.', ['number' => $card->control_number]));
        } catch (CardIssuanceRefusedException|UnitAtCapacityException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->reissueOffered = false;
    }

    /** Opens the edit modal, staging the relationship's current contract end date. */
    public function openEditContractEndDate(int $relationshipId): void
    {
        $relationship = PersonUnitRelationship::findOrFail($relationshipId);
        $this->authorize('update', $relationship);

        $this->editingRelationshipId = $relationshipId;
        $this->editContractEndDate = $relationship->contract_end_date?->format('Y-m-d') ?? '';
        $this->dispatch('open-modal', 'edit-contract-end-date');
    }

    public function saveContractEndDate(RelationshipManager $relationships): void
    {
        $relationship = PersonUnitRelationship::findOrFail($this->editingRelationshipId);
        $this->authorize('update', $relationship);

        $validated = $this->validate([
            'editContractEndDate' => ['nullable', 'date'],
        ], [], [], 'editContractEndDate');

        // The modal's Save button dispatches close-modal client-side the
        // instant it's clicked (same shape as confirm-relationship's own
        // Confirm button), so an addError() here would never actually be
        // seen — session flash is what closeRelationshipNow() already uses
        // for exactly this reason.
        try {
            $relationships->updateContractEndDate(auth()->user(), $relationship, $validated['editContractEndDate'] ?: null);
        } catch (InvalidContractEndDateException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->editingRelationshipId = 0;
        $this->editContractEndDate = '';
        session()->flash('status', __('Contract end date updated.'));
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

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form wire:submit="save" class="space-y-4">

                    <div class="space-y-2">
                        <h3 class="text-lg font-medium">{{ __('Photo') }}</h3>

                        <div class="flex flex-wrap items-center gap-6">
                            @if ($photo)
                                <div class="flex flex-wrap items-center gap-4">
                                    <div class="shrink-0">
                                        <img src="{{ $photo->temporaryUrl() }}" alt="" class="w-24 h-24 shrink-0 object-cover rounded-md border">
                                        @if ($photo->getSize() !== false)
                                            <p class="text-xs text-gray-400 mt-1 text-center">{{ \Illuminate\Support\Number::fileSize($photo->getSize(), precision: 1) }}</p>
                                        @endif
                                    </div>
                                    @can('update', $person)
                                        <x-secondary-button type="button" wire:click="clearStagedPhoto">{{ __('Clear') }}</x-secondary-button>
                                    @endcan
                                </div>
                            @else
                                @if ($person->photo_path)
                                    <div class="shrink-0">
                                        <img src="{{ route('people.photo', $person) }}?v={{ $person->updated_at?->timestamp }}" alt="" class="w-24 h-24 shrink-0 object-cover rounded-md border">
                                        <p class="text-xs text-gray-400 mt-1 text-center">{{ $this->photoSizeLabel() }}</p>
                                    </div>
                                @else
                                    <div class="w-24 h-24 shrink-0 flex items-center justify-center rounded-md border text-xs text-gray-400 text-center">
                                        {{ __('No photo') }}
                                    </div>
                                @endif

                                @can('update', $person)
                                    <div class="flex flex-wrap items-start gap-6">
                                        <div>
                                            <x-cropping-file-input name="photo" />
                                            <p class="text-xs text-gray-400 mt-1">{{ __('JPEG or PNG, up to 1MB. Non-square photos open a crop tool. Takes effect on Save.') }}</p>
                                        </div>
                                        <x-camera-capture name="photo" />
                                    </div>
                                @endcan
                            @endif
                        </div>

                        <x-input-error :messages="$errors->get('photo')" class="mt-2" />
                    </div>

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
                        <div class="flex justify-end gap-3">
                            <x-secondary-button type="button" wire:click="resetForm">{{ __('Reset') }}</x-secondary-button>
                            <x-primary-button>{{ __('Save') }}</x-primary-button>
                        </div>
                    @endcan
                </form>
            </div>

            @if ($reissueOffered)
                <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg flex items-center justify-between gap-4">
                    <span>{{ __('This person still holds another active relationship — a replacement card can be issued now.') }}</span>
                    <div class="flex gap-2 shrink-0">
                        <x-secondary-button type="button" wire:click="$set('reissueOffered', false)">{{ __('Not now') }}</x-secondary-button>
                        <x-primary-button type="button" wire:click="issueOfferedReplacement">{{ __('Issue replacement card') }}</x-primary-button>
                    </div>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg overflow-x-auto">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium">{{ __('Units') }}</h3>
                    <label class="flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" wire:model.live="showEndedRelationships" class="rounded border-gray-300">
                        {{ __('Show ended relationships') }}
                    </label>
                </div>
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="py-2 pr-4">{{ __('Unit') }}</th>
                            <th class="py-2 pr-4">{{ __('Type') }}</th>
                            <th class="py-2 pr-4">{{ __('Primary?') }}</th>
                            <th class="py-2 pr-4">{{ __('Start') }}</th>
                            <th class="py-2 pr-4">{{ __('Contract end') }}</th>
                            <th class="py-2 pr-4">{{ __('Status') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($relationships as $relationship)
                            <tr class="border-b" wire:key="rel-{{ $relationship->id }}">
                                <td class="py-2 pr-4">
                                    <a href="{{ route('units.show', $relationship->unit) }}" wire:navigate class="underline text-gray-600 hover:text-gray-900 font-mono">
                                        {{ $relationship->unit->unitCode() }}
                                    </a>
                                </td>
                                <td class="py-2 pr-4">{{ ucfirst($relationship->type) }}</td>
                                <td class="py-2 pr-4">{{ $relationship->is_primary_owner ? __('Yes') : __('No') }}</td>
                                <td class="py-2 pr-4">{{ $relationship->start_date->format('Y-m-d') }}</td>
                                <td class="py-2 pr-4">{{ $relationship->contract_end_date?->format('Y-m-d') ?? '—' }}</td>
                                <td class="py-2 pr-4">{{ $relationship->ended_at ? __('Ended :date', ['date' => $relationship->ended_at->format('Y-m-d')]) : __('Active') }}</td>
                                <td class="py-2">
                                    @if (is_null($relationship->ended_at))
                                        <div class="flex gap-3">
                                            <button wire:click="openEditContractEndDate({{ $relationship->id }})" type="button" class="underline text-sm text-gray-600 hover:text-gray-900">
                                                {{ __('Edit') }}
                                            </button>
                                            @if (! $relationship->is_primary_owner)
                                                <button wire:click="stageCloseRelationship({{ $relationship->id }})" wire:confirm="{{ __('End this relationship?') }}" type="button" class="underline text-sm text-gray-600 hover:text-gray-900">
                                                    {{ __('End') }}
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-6 text-center text-gray-500">
                                    {{ $showEndedRelationships ? __('No relationships at all.') : __('No active relationships.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @can('delete', $person)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-lg font-medium mb-2">{{ __('Delete') }}</h3>
                    <p class="text-sm text-gray-500 mb-4">{{ __('Refused while this person is a unit\'s primary owner, or holds any active relationship or card.') }}</p>
                    <x-danger-button type="button" wire:click="delete" wire:confirm="{{ __('Delete this person?') }}">{{ __('Delete') }}</x-danger-button>
                </div>
            @endcan
        </div>
    </div>

    <x-confirm-dialog name="mandatory-reissue" :title="__('This will reissue :count card(s)', ['count' => count($reissuePreviewCards)])" confirmAction="confirmReissue" :confirmLabel="__('Save and reissue')">
        <p class="mb-3">{{ __('A photo or name change is printed on every active card. The following will be replaced with a new card each — this cannot be undone:') }}</p>
        <ul class="list-disc list-inside space-y-1">
            @foreach ($reissuePreviewCards as $card)
                <li>
                    <span class="font-mono">#{{ $card['control_number'] }}</span>
                    ({{ ucfirst($card['type']) }}@if ($card['unit_code']), {{ __('Unit :code', ['code' => $card['unit_code']]) }}@endif)
                </li>
            @endforeach
        </ul>
    </x-confirm-dialog>

    <x-confirm-dialog name="close-relationship" :title="__('Ending this relationship will expire :count card(s)', ['count' => count($closePreviewCards)])" confirmAction="confirmCloseRelationship" :confirmLabel="__('End and expire')">
        <p class="mb-3">{{ __('The following active cards will be expired — this cannot be undone:') }}</p>
        <ul class="list-disc list-inside space-y-1">
            @foreach ($closePreviewCards as $card)
                <li><span class="font-mono">#{{ $card['control_number'] }}</span> ({{ ucfirst($card['type']) }})</li>
            @endforeach
        </ul>
    </x-confirm-dialog>

    <x-confirm-dialog name="edit-contract-end-date" :title="__('Edit contract end date')" confirmAction="saveContractEndDate" :confirmLabel="__('Save')">
        <p class="mb-3">{{ __('Informational only — never drives status (rule 4). Leave blank for no fixed term.') }}</p>
        <x-form-field name="editContractEndDate" :label="__('Contract end date')">
            <x-text-input wire:model="editContractEndDate" id="editContractEndDate" class="block mt-1 w-full" type="date" />
        </x-form-field>
    </x-confirm-dialog>
</div>
