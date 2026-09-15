<?php

use App\Models\Template;
use App\Services\TemplateManager;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $id_type = 'owner';

    public string $name = '';

    public string $orientation = Template::ORIENTATION_LANDSCAPE;

    public function mount(): void
    {
        $this->authorize('create', Template::class);
    }

    public function create(TemplateManager $templates): void
    {
        $this->authorize('create', Template::class);

        $validated = $this->validate([
            'id_type' => ['required', Rule::in(Template::ID_TYPES)],
            'name' => ['required', 'string', 'max:120'],
            'orientation' => ['required', Rule::in([Template::ORIENTATION_LANDSCAPE, Template::ORIENTATION_PORTRAIT])],
        ]);

        $template = $templates->createTemplate(auth()->user(), $validated['id_type'], $validated['name'], $validated['orientation']);

        $this->redirect(route('templates.show', $template), navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('New template') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <form wire:submit="create" class="space-y-6">
                    <x-form-field name="name" :label="__('Name')" hint="{{ __('For your own reference — never printed on the card.') }}">
                        <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" />
                    </x-form-field>

                    <x-form-field name="id_type" :label="__('Card type')">
                        <select wire:model="id_type" id="id_type" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                            <option value="owner">{{ __('Unit owner') }}</option>
                            <option value="tenant">{{ __('Tenant') }}</option>
                            <option value="employee">{{ __('Employee') }}</option>
                        </select>
                    </x-form-field>

                    <x-form-field name="orientation" :label="__('Orientation')" hint="{{ __('Fixed once artwork or field positions exist — a different orientation means a new template.') }}">
                        <div class="mt-2 flex gap-6">
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" wire:model="orientation" value="landscape">
                                <span>{{ __('Landscape (1011×638px)') }}</span>
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" wire:model="orientation" value="portrait">
                                <span>{{ __('Portrait (638×1011px)') }}</span>
                            </label>
                        </div>
                    </x-form-field>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('templates.index') }}" wire:navigate>
                            <x-secondary-button type="button">{{ __('Cancel') }}</x-secondary-button>
                        </a>
                        <x-primary-button type="submit">{{ __('Create') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
