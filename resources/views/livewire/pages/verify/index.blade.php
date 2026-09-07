<?php

use App\Services\CardVerificationService;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * The guardhouse flow (architecture §8, Phase 10 plan). Open to every role
 * — Superadmin and Admin can use it for spot checks, but a Reader account
 * exists for exactly this screen and nothing else. No policy gate beyond
 * `auth`: unlike the photo it can surface, the verify *result* itself
 * (status, name, unit, type) is not the sensitive part — the plaintext
 * control number is already printed on the card (§8).
 */
new #[Layout('layouts.app')] class extends Component
{
    public string $manualControlNumber = '';

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public function scan(string $decodedText, CardVerificationService $verification): void
    {
        $this->runVerify($decodedText, $verification);
    }

    public function verifyManual(CardVerificationService $verification): void
    {
        $this->validate(['manualControlNumber' => ['required', 'string']]);

        $this->runVerify($this->manualControlNumber, $verification);
    }

    private function runVerify(string $raw, CardVerificationService $verification): void
    {
        $outcome = $verification->verify(auth()->user(), $raw);
        $card = $outcome['card'];

        if ($card === null) {
            $this->result = ['found' => false, 'attempted' => $raw];
            $this->manualControlNumber = '';

            return;
        }

        $this->result = [
            'found' => true,
            'status' => $card->status,
            'control_number' => $card->control_number,
            'type' => $card->type,
            'unit_code' => $card->unit?->unitCode(),
            'person_id' => $card->person_id,
            'person_name' => $card->person->displayName(),
            'position' => $card->position,
            'department' => $card->department,
        ];

        $this->manualControlNumber = '';
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Verify') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium mb-4">{{ __('Scan a card') }}</h3>
                <x-qr-scanner on-decode="scan" />
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium mb-2">{{ __('Or enter the control number') }}</h3>
                <form wire:submit="verifyManual" class="flex flex-wrap items-end gap-4">
                    <div class="flex-1 min-w-[10rem]">
                        <x-form-field name="manualControlNumber" :label="__('Control number')">
                            <x-text-input wire:model="manualControlNumber" id="manualControlNumber" class="block mt-1 w-full font-mono" type="text" inputmode="numeric" placeholder="00000000" />
                        </x-form-field>
                    </div>
                    <x-primary-button>{{ __('Verify') }}</x-primary-button>
                </form>
            </div>

            @if ($result)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg" wire:key="result-{{ $result['found'] ? $result['control_number'] : $result['attempted'] }}">
                    @if ($result['found'])
                        <div class="flex items-start gap-6 flex-wrap">
                            <img src="{{ route('people.photo', $result['person_id']) }}" alt="" class="w-32 h-32 shrink-0 object-cover rounded-md border">

                            <div class="space-y-2">
                                <div class="flex items-center gap-3">
                                    <span class="text-xl font-semibold">{{ $result['person_name'] }}</span>
                                    <x-status-badge :status="$result['status']" class="text-sm px-3 py-1" />
                                </div>

                                @if ($result['status'] !== 'active')
                                    <p class="text-red-700 font-medium">{{ __('This card is not active — do not admit on this scan alone.') }}</p>
                                @endif

                                <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm text-gray-600 max-w-sm">
                                    <dt>{{ __('Control number') }}</dt>
                                    <dd class="font-mono">{{ $result['control_number'] }}</dd>
                                    <dt>{{ __('Type') }}</dt>
                                    <dd>{{ ucfirst($result['type']) }}</dd>
                                    @if ($result['unit_code'])
                                        <dt>{{ __('Unit') }}</dt>
                                        <dd>{{ $result['unit_code'] }}</dd>
                                    @endif
                                    @if ($result['position'])
                                        <dt>{{ __('Position') }}</dt>
                                        <dd>{{ $result['position'] }}</dd>
                                    @endif
                                    @if ($result['department'])
                                        <dt>{{ __('Department') }}</dt>
                                        <dd>{{ $result['department'] }}</dd>
                                    @endif
                                </dl>
                            </div>
                        </div>
                    @else
                        <p class="text-red-700">{{ __('No card found for ":value".', ['value' => $result['attempted']]) }}</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
