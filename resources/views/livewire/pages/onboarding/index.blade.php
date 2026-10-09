<?php

use App\Models\SiteSetting;
use App\Services\PersonImporter;
use App\Services\UnitImporter;
use App\Support\SiteTime;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Phase 21 plan. Two independent panels, Persons and Units — a Superadmin
 * can use either in any order (docs/implementation-plan.md, "Two
 * independent options, not a sequence"); a units row just needs its
 * primary owner to already resolve to a real, contactable person, which
 * in practice usually means running persons first on a brand-new condo.
 *
 * Both panels follow the same three-step shape: upload → preview (every
 * row validated, nothing written) → confirm → import (re-validated fresh
 * inside one all-or-nothing transaction). The Import button stays
 * disabled while any row has an error — there is no "import the good
 * rows" mode (rule: a half-imported file is harder to fix than a
 * refused one).
 */
new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $personsFile = null;

    public ?array $personsPreview = null;

    public bool $confirmingPersonsImport = false;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $unitsFile = null;

    public ?array $unitsPreview = null;

    public bool $confirmingUnitsImport = false;

    public function mount(): void
    {
        $this->authorize('bulk-onboard');
    }

    public function downloadPersonsTemplate(PersonImporter $importer)
    {
        $this->authorize('bulk-onboard');

        return response()->streamDownload(function () use ($importer) {
            echo $importer->templateCsv();
        }, SiteSetting::current()->filenameSlug().'-persons-template.csv');
    }

    public function downloadUnitsTemplate(UnitImporter $importer)
    {
        $this->authorize('bulk-onboard');

        return response()->streamDownload(function () use ($importer) {
            echo $importer->templateCsv();
        }, SiteSetting::current()->filenameSlug().'-units-template.csv');
    }

    public function updatedPersonsFile(PersonImporter $importer): void
    {
        $this->authorize('bulk-onboard');
        $this->resetErrorBag('personsFile');
        $this->personsPreview = null;

        $this->validate(['personsFile' => ['file', 'mimes:csv,txt']]);

        try {
            $this->personsPreview = $importer->preview($this->personsFile);
        } catch (InvalidArgumentException $e) {
            $this->addError('personsFile', $e->getMessage());
            $this->reset('personsFile');
        }
    }

    public function updatedUnitsFile(UnitImporter $importer): void
    {
        $this->authorize('bulk-onboard');
        $this->resetErrorBag('unitsFile');
        $this->unitsPreview = null;

        $this->validate(['unitsFile' => ['file', 'mimes:csv,txt']]);

        try {
            $this->unitsPreview = $importer->preview($this->unitsFile);
        } catch (InvalidArgumentException $e) {
            $this->addError('unitsFile', $e->getMessage());
            $this->reset('unitsFile');
        }
    }

    public function stagePersonsImport(): void
    {
        $this->confirmingPersonsImport = true;
    }

    public function cancelPersonsImport(): void
    {
        $this->confirmingPersonsImport = false;
    }

    public function stageUnitsImport(): void
    {
        $this->confirmingUnitsImport = true;
    }

    public function cancelUnitsImport(): void
    {
        $this->confirmingUnitsImport = false;
    }

    public function importPersons(PersonImporter $importer)
    {
        $this->authorize('bulk-onboard');
        $this->confirmingPersonsImport = false;
        $this->resetErrorBag('personsFile');

        try {
            $result = $importer->import(auth()->user(), $this->personsFile, $this->personsFile->getClientOriginalName());
        } catch (InvalidArgumentException $e) {
            $this->addError('personsFile', $e->getMessage());

            return;
        }

        $count = count($result['results']);
        $this->reset('personsFile', 'personsPreview');
        session()->flash('status', __(':count person(s) imported. The results file — with each new ID number — is downloading now.', ['count' => $count]));

        return response()->streamDownload(function () use ($result) {
            echo $result['csv'];
        }, SiteSetting::current()->filenameSlug().'-persons-imported-'.SiteTime::now()->format('Y-m-d-Hi').'.csv');
    }

    public function importUnits(UnitImporter $importer)
    {
        $this->authorize('bulk-onboard');
        $this->confirmingUnitsImport = false;
        $this->resetErrorBag('unitsFile');

        try {
            $result = $importer->import(auth()->user(), $this->unitsFile, $this->unitsFile->getClientOriginalName());
        } catch (InvalidArgumentException $e) {
            $this->addError('unitsFile', $e->getMessage());

            return;
        }

        $this->reset('unitsFile', 'unitsPreview');
        session()->flash('status', __(':count unit(s) imported, each with its primary owner.', ['count' => $result['count']]));
    }

    public function with(): array
    {
        return [
            'personsWarningCount' => $this->countWarnings($this->personsPreview),
            'unitsWarningCount' => $this->countWarnings($this->unitsPreview),
        ];
    }

    /**
     * @param  array{rows: array<int, array<string, mixed>>, hasErrors: bool}|null  $preview
     */
    private function countWarnings(?array $preview): int
    {
        if ($preview === null) {
            return 0;
        }

        return collect($preview['rows'])->sum(fn ($row) => count($row['warnings'] ?? []));
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Bulk Onboarding') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <x-toast :message="session('status')" />

            {{-- Persons panel --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium">{{ __('Persons') }}</h3>
                        <p class="text-sm text-gray-500">{{ __('Creates natural-person records at the minimal tier or above. Every row imports fresh — no updates to existing people.') }}</p>
                    </div>
                    <x-secondary-button type="button" wire:click="downloadPersonsTemplate">{{ __('Download template') }}</x-secondary-button>
                </div>

                <ul class="text-xs text-gray-500 space-y-1 list-disc list-inside">
                    <li>{{ __('First Name (String; required): Given name of the person') }}</li>
                    <li>{{ __('Last Name (String; required): Family name of the person') }}</li>
                    <li>{{ __('Email (String; optional): Email address of the person; must be a valid email format if provided') }}</li>
                    <li>{{ __('Phone Number (String; optional): Mobile number of the person') }}</li>
                    <li>{{ __('Middle Name (String; optional): Middle name of the person') }}</li>
                    <li>{{ __('Suffix (String; optional): Name suffix, e.g. Jr., Sr., III') }}</li>
                    <li>{{ __('Gender (String; optional): One of "Male", "Female", or "Prefer not to say"') }}</li>
                    <li>{{ __('Date of Birth (String; 10 characters; optional): Date of birth of the person; of format "yyyy-mm-dd"') }}</li>
                    <li>{{ __('Place of Birth (String; optional): Place where the person was born') }}</li>
                    <li>{{ __('Home Address (String; optional): Residential address of the person') }}</li>
                    <li>{{ __('Landline Number (String; optional): Landline/telephone number of the person') }}</li>
                    <li>{{ __('Emergency Contact Name (String; optional): Name of the person\'s emergency contact') }}</li>
                    <li>{{ __('Emergency Contact Number (String; optional): Phone number of the person\'s emergency contact') }}</li>
                    <li>{{ __('Emergency Contact Relation (String; optional): Relationship of the emergency contact to the person, e.g. Spouse, Parent') }}</li>
                    <li>{{ __('Notes (String; optional): Free-form remarks about the person') }}</li>
                </ul>

                <div>
                    <input type="file" wire:model="personsFile" accept=".csv,text/csv" class="block w-full text-sm text-gray-600" />
                    <x-input-error :messages="$errors->get('personsFile')" class="mt-2" />
                </div>

                @if ($personsPreview)
                    <div class="space-y-2">
                        <p class="text-sm text-gray-600">
                            {{ __(':count row(s).', ['count' => count($personsPreview['rows'])]) }}
                            @if ($personsWarningCount > 0)
                                {{ __(':count possible-duplicate warning(s).', ['count' => $personsWarningCount]) }}
                            @endif
                        </p>

                        <x-data-table>
                            <x-slot name="head">
                                <th class="py-2 pr-4">{{ __('Row') }}</th>
                                <th class="py-2 pr-4">{{ __('Name') }}</th>
                                <th class="py-2 pr-4">{{ __('Email') }}</th>
                                <th class="py-2 pr-4">{{ __('Phone') }}</th>
                                <th class="py-2">{{ __('Errors / warnings') }}</th>
                            </x-slot>

                            @foreach ($personsPreview['rows'] as $rowNumber => $row)
                                <tr class="border-b {{ $row['errors'] ? 'bg-red-50' : '' }}" wire:key="persons-row-{{ $rowNumber }}">
                                    <td class="py-2 pr-4 font-mono">{{ $rowNumber }}</td>
                                    <td class="py-2 pr-4">{{ trim(($row['data']['First Name'] ?? '').' '.($row['data']['Last Name'] ?? '')) }}</td>
                                    <td class="py-2 pr-4">{{ $row['data']['Email'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $row['data']['Phone Number'] ?? '—' }}</td>
                                    <td class="py-2">
                                        @foreach ($row['errors'] as $error)
                                            <div class="text-red-700 text-xs">{{ $error }}</div>
                                        @endforeach
                                        @foreach ($row['warnings'] as $warning)
                                            <div class="text-amber-700 text-xs">{{ $warning }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </x-data-table>

                        <div class="flex justify-end">
                            <x-primary-button type="button" wire:click="stagePersonsImport" :disabled="$personsPreview['hasErrors']">
                                {{ __('Import persons') }}
                            </x-primary-button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Units panel --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium">{{ __('Units') }}</h3>
                        <p class="text-sm text-gray-500">{{ __('Creates each unit with its primary owner, referenced by that person\'s 8-digit ID number — a new person or an existing one.') }}</p>
                    </div>
                    <x-secondary-button type="button" wire:click="downloadUnitsTemplate">{{ __('Download template') }}</x-secondary-button>
                </div>

                <ul class="text-xs text-gray-500 space-y-1 list-disc list-inside">
                    <li>{{ __('Building Code (String; 1-2 alphanumeric characters; optional): Code of the building') }}</li>
                    <li>{{ __('Floor (String; 1-2 characters; required): Floor of the unit') }}</li>
                    <li>{{ __('Unit Number (String; 1-2 characters; required): Number of the unit') }}</li>
                    <li>{{ __('Primary Owner ID Number (String; 8 characters; required): ID number of the person who will own the unit primarily') }}</li>
                    <li>{{ __('Date First Owned (String; 10 characters; required): Date first owned or encoded by the unit owner; of format "yyyy-mm-dd"') }}</li>
                </ul>

                <div>
                    <input type="file" wire:model="unitsFile" accept=".csv,text/csv" class="block w-full text-sm text-gray-600" />
                    <x-input-error :messages="$errors->get('unitsFile')" class="mt-2" />
                </div>

                @if ($unitsPreview)
                    <div class="space-y-2">
                        <p class="text-sm text-gray-600">{{ __(':count row(s).', ['count' => count($unitsPreview['rows'])]) }}</p>

                        <x-data-table>
                            <x-slot name="head">
                                <th class="py-2 pr-4">{{ __('Row') }}</th>
                                <th class="py-2 pr-4">{{ __('Unit code') }}</th>
                                <th class="py-2 pr-4">{{ __('Owner ID') }}</th>
                                <th class="py-2 pr-4">{{ __('Date first owned') }}</th>
                                <th class="py-2">{{ __('Errors') }}</th>
                            </x-slot>

                            @foreach ($unitsPreview['rows'] as $rowNumber => $row)
                                <tr class="border-b {{ $row['errors'] ? 'bg-red-50' : '' }}" wire:key="units-row-{{ $rowNumber }}">
                                    <td class="py-2 pr-4 font-mono">{{ $rowNumber }}</td>
                                    <td class="py-2 pr-4 font-mono">{{ trim(($row['data']['Building Code'] ?? '').($row['data']['Floor'] ?? '').($row['data']['Unit Number'] ?? '')) }}</td>
                                    <td class="py-2 pr-4 font-mono">{{ $row['data']['Primary Owner ID Number'] ?? '—' }}</td>
                                    <td class="py-2 pr-4">{{ $row['data']['Date First Owned'] ?? '—' }}</td>
                                    <td class="py-2">
                                        @foreach ($row['errors'] as $error)
                                            <div class="text-red-700 text-xs">{{ $error }}</div>
                                        @endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </x-data-table>

                        <div class="flex justify-end">
                            <x-primary-button type="button" wire:click="stageUnitsImport" :disabled="$unitsPreview['hasErrors']">
                                {{ __('Import units') }}
                            </x-primary-button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <x-confirm-dialog :open="$confirmingPersonsImport" :title="__('Import these persons?')" confirm-action="importPersons" cancel-action="cancelPersonsImport">
        <p>
            {{ __('Every row is created as a new person. This cannot be undone by re-uploading the same file.') }}
            @if ($personsWarningCount > 0)
                {{ __(':count possible-duplicate warning(s) will still be imported — check the preview before confirming.', ['count' => $personsWarningCount]) }}
            @endif
        </p>
    </x-confirm-dialog>

    <x-confirm-dialog :open="$confirmingUnitsImport" :title="__('Import these units?')" confirm-action="importUnits" cancel-action="cancelUnitsImport">
        <p>{{ __('Every row creates a unit and its primary-owner relationship. This cannot be undone by re-uploading the same file.') }}</p>
    </x-confirm-dialog>
</div>
