<?php

use App\Models\Template;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Template::class);
    }

    public function with(): array
    {
        return [
            'templates' => Template::query()
                ->orderByRaw("array_position(array['owner','tenant','employee'], id_type)")
                ->orderByDesc('is_active')
                ->orderByDesc('created_at')
                ->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Templates') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <p class="text-sm text-gray-600 max-w-2xl">
                        {{ __('One active template per card type at a time. Activating a new one for a type automatically retires whichever was active for it.') }}
                    </p>

                    @can('create', Template::class)
                        <a href="{{ route('templates.create') }}" wire:navigate>
                            <x-primary-button type="button">{{ __('+ New template') }}</x-primary-button>
                        </a>
                    @endcan
                </div>

                <x-data-table>
                    <x-slot name="head">
                        <th class="py-2 pr-4">{{ __('Name') }}</th>
                        <th class="py-2 pr-4">{{ __('Type') }}</th>
                        <th class="py-2 pr-4">{{ __('Orientation') }}</th>
                        <th class="py-2 pr-4">{{ __('Status') }}</th>
                        <th class="py-2"></th>
                    </x-slot>

                    @forelse ($templates as $template)
                        <tr class="border-b" wire:key="template-{{ $template->id }}">
                            <td class="py-2 pr-4">{{ $template->name }}</td>
                            <td class="py-2 pr-4 capitalize">{{ $template->id_type }}</td>
                            <td class="py-2 pr-4 capitalize">{{ $template->orientation() }}</td>
                            <td class="py-2 pr-4">
                                @if ($template->is_active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{{ __('Active') }}</span>
                                @elseif ($template->isComplete())
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">{{ __('Ready') }}</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{{ __('Incomplete') }}</span>
                                @endif
                            </td>
                            <td class="py-2">
                                <a href="{{ route('templates.show', $template) }}" wire:navigate class="underline text-sm text-gray-600 hover:text-gray-900">
                                    {{ __('Open') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="5" />
                    @endforelse
                </x-data-table>
            </div>
        </div>
    </div>
</div>
