<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Component Preview') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-8">

            <div class="p-6 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium mb-4">{{ __('Buttons') }}</h3>
                <div class="flex gap-3">
                    <x-primary-button type="button">{{ __('Primary') }}</x-primary-button>
                    <x-secondary-button type="button">{{ __('Secondary') }}</x-secondary-button>
                    <x-danger-button type="button">{{ __('Danger') }}</x-danger-button>
                </div>
            </div>

            <div class="p-6 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium mb-4">{{ __('Status badge') }}</h3>
                <div class="flex gap-2">
                    <x-status-badge status="active" />
                    <x-status-badge status="lost" />
                    <x-status-badge status="revoked" />
                    <x-status-badge status="expired" />
                    <x-status-badge status="replaced" />
                </div>
            </div>

            <div class="p-6 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium mb-4">{{ __('Form field') }}</h3>
                <div class="max-w-sm">
                    <x-form-field name="preview_example" :label="__('Control number')" hint="{{ __('8 digits, zero-padded.') }}">
                        <x-text-input id="preview_example" class="block mt-1 w-full" type="text" placeholder="00451234" />
                    </x-form-field>
                </div>
            </div>

            <div class="p-6 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium mb-4">{{ __('Data table') }}</h3>
                <p class="text-sm text-gray-500 mb-4">{{ __('Click a column header — this sort is real, via HasSortableColumns.') }}</p>

                <x-data-table>
                    <x-slot name="head">
                        <x-data-table.sort-header column="control_number" :current="$sortColumn" :direction="$sortDirection">{{ __('Control #') }}</x-data-table.sort-header>
                        <x-data-table.sort-header column="unit" :current="$sortColumn" :direction="$sortDirection">{{ __('Unit') }}</x-data-table.sort-header>
                        <x-data-table.sort-header column="type" :current="$sortColumn" :direction="$sortDirection">{{ __('Type') }}</x-data-table.sort-header>
                        <th class="py-2">{{ __('Status') }}</th>
                    </x-slot>

                    @forelse ($this->sampleRows() as $card)
                        <tr class="border-b" wire:key="preview-card-{{ $card['control_number'] }}">
                            <td class="py-2 pr-4 font-mono">{{ $card['control_number'] }}</td>
                            <td class="py-2 pr-4">{{ $card['unit'] }}</td>
                            <td class="py-2 pr-4">{{ ucfirst($card['type']) }}</td>
                            <td class="py-2"><x-status-badge :status="$card['status']" /></td>
                        </tr>
                    @empty
                        <x-data-table.empty colspan="4" />
                    @endforelse
                </x-data-table>
            </div>

            <div class="p-6 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium mb-4">{{ __('Confirm dialog') }}</h3>
                <x-secondary-button type="button" x-on:click="$dispatch('open-modal', 'preview-confirm')">
                    {{ __('Open confirm dialog') }}
                </x-secondary-button>

                <x-confirm-dialog name="preview-confirm" :title="__('Revoke card #00451234?')" :confirm-label="__('Revoke')" :danger="true">
                    {{ __('This card will stop verifying immediately. This preview button has no confirmAction wired, so Confirm just closes the dialog.') }}
                </x-confirm-dialog>
            </div>

            <div class="p-6 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium mb-4">{{ __('Toast') }}</h3>
                <x-secondary-button type="button" wire:click="fireToast">
                    {{ __('Fire toast') }}
                </x-secondary-button>

                <div class="mt-4">
                    <x-toast on="preview-toast-fired" variant="success">
                        {{ __('This is what a success toast looks like.') }}
                    </x-toast>
                </div>
            </div>

        </div>
    </div>
</div>
