<?php

use App\Models\IdCard;
use App\Services\CardPrintService;
use App\Services\IdCardLifecycleManager;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public IdCard $idCard;

    public string $reason = '';

    /** 'lost' | 'revoke' | 'expire' | null — which confirm dialog is open. */
    public ?string $pendingAction = null;

    public function mount(IdCard $idCard): void
    {
        $this->idCard = $idCard;
        $this->authorize('manageLifecycle', IdCard::class);
    }

    public function with(): array
    {
        $this->idCard->load(['person', 'unit', 'template', 'replaces', 'replacedBy']);

        return [];
    }

    public function stage(string $action): void
    {
        $this->pendingAction = $action;
        $this->reason = '';
    }

    public function cancelStaged(): void
    {
        $this->pendingAction = null;
    }

    public function confirmStaged(IdCardLifecycleManager $lifecycle): void
    {
        $this->authorize('update', $this->idCard);

        $validated = $this->validate(['reason' => ['required', 'string', 'max:255']]);
        $action = $this->pendingAction;

        try {
            $newCard = match ($action) {
                'lost' => $lifecycle->markLost(auth()->user(), $this->idCard, $validated['reason']),
                'revoke' => $lifecycle->revoke(auth()->user(), $this->idCard, $validated['reason']),
                // The Expire button is already hidden for a non-tenant card
                // (see the template below) — this catch is the server-side
                // backstop for a stale page or a direct call bypassing the
                // UI, not the primary defense. Either way it must not 500.
                'expire' => $lifecycle->expire(auth()->user(), $this->idCard, $validated['reason']),
                default => null,
            };
        } catch (InvalidArgumentException $e) {
            $this->pendingAction = null;
            $this->reason = '';
            $this->addError('reason', $e->getMessage());

            return;
        }

        $this->pendingAction = null;
        $this->reason = '';

        // markLost() issues a replacement card — go there directly, since
        // that's now the card an admin actually cares about; revoke()/
        // expire() touch only this card, so stay on it.
        if ($action === 'lost' && $newCard !== null) {
            $this->redirect(route('id-cards.show', $newCard), navigate: true);

            return;
        }

        $this->idCard->refresh();
    }

    /**
     * Returning a download-shaped Response from a Livewire action is what
     * triggers the browser's save-file dialog — Livewire's own
     * SupportFileDownloads hook intercepts it rather than trying to
     * render it as the component's HTML. `CardPrintService::print()` sets
     * `printed_at` and writes the audit row in the same call the zip is
     * built from, so a card is never left "printed" with no zip having
     * actually been generated, or vice versa.
     */
    public function print(CardPrintService $prints)
    {
        $this->authorize('manageLifecycle', IdCard::class);

        // No validate() call happens here, so nothing clears a previous
        // failure's message on its own — see templates/show.blade.php's
        // savePositions() for the same fix and the full reasoning.
        $this->resetErrorBag('print');

        try {
            $zip = $prints->print(auth()->user(), $this->idCard);
        } catch (InvalidArgumentException $e) {
            $this->addError('print', $e->getMessage());

            return;
        }

        $this->idCard->refresh();

        return response()->streamDownload(function () use ($zip) {
            echo $zip;
        }, "card-{$this->idCard->control_number}.zip");
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Card #:number', ['number' => $idCard->control_number]) }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <dl class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
                        <dt class="text-gray-500">{{ __('Person') }}</dt>
                        <dd>{{ $idCard->person->displayName() }}</dd>

                        <dt class="text-gray-500">{{ __('Type') }}</dt>
                        <dd class="capitalize">{{ $idCard->type }}</dd>

                        <dt class="text-gray-500">{{ __('Unit') }}</dt>
                        <dd>{{ $idCard->unit?->unitCode() ?? '—' }}</dd>

                        <dt class="text-gray-500">{{ __('Status') }}</dt>
                        <dd><x-status-badge :status="$idCard->status" /></dd>

                        <dt class="text-gray-500">{{ __('Issued') }}</dt>
                        <dd>{{ $idCard->issued_at?->format('Y-m-d H:i') }}</dd>

                        <dt class="text-gray-500">{{ __('Template') }}</dt>
                        <dd>
                            @if ($idCard->template)
                                <a href="{{ route('templates.show', $idCard->template) }}" wire:navigate class="underline">{{ $idCard->template->name }}</a>
                            @else
                                <span class="text-gray-400">{{ __('none on record') }}</span>
                            @endif
                        </dd>

                        @if ($idCard->replaces)
                            <dt class="text-gray-500">{{ __('Replaces') }}</dt>
                            <dd>
                                <a href="{{ route('id-cards.show', $idCard->replaces) }}" wire:navigate class="underline font-mono">{{ $idCard->replaces->control_number }}</a>
                            </dd>
                        @endif
                    </dl>

                    @if ($idCard->status === 'active')
                        <div class="flex flex-wrap gap-2">
                            <x-secondary-button type="button" wire:click="stage('lost')">{{ __('Mark lost') }}</x-secondary-button>
                            <x-danger-button type="button" wire:click="stage('revoke')">{{ __('Revoke') }}</x-danger-button>
                            {{-- Expire is a lease running out — the only thing rule 6 defines
                                 "expired" against. An owner's or employee's entitlement never
                                 lapses on its own; ending either is always a deliberate
                                 decision, which revoke already means. --}}
                            @if ($idCard->type === 'tenant')
                                <x-secondary-button type="button" wire:click="stage('expire')">{{ __('Expire') }}</x-secondary-button>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            @if ($idCard->template)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <h3 class="text-lg font-medium">{{ __('Rendered card') }}</h3>

                        @if ($idCard->isPrinted())
                            {{-- printed_at is a historical fact — stays visible even if the
                                 card later became lost/revoked/expired. --}}
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                {{ __('Printed :date', ['date' => $idCard->printed_at->format('Y-m-d H:i')]) }}
                            </span>
                        @elseif ($idCard->status === 'active')
                            <x-primary-button type="button" wire:click="print" wire:confirm="{{ __('Download the front/back zip and mark this card printed? This cannot be undone — a card can only be printed once.') }}">
                                {{ __('Print (download zip)') }}
                            </x-primary-button>
                        @else
                            <span class="text-sm text-gray-400">{{ __('Cannot print — card is :status', ['status' => $idCard->status]) }}</span>
                        @endif
                    </div>
                    <x-input-error :messages="$errors->get('print')" class="mt-1" />

                    <div class="flex flex-wrap gap-6">
                        <div class="space-y-1">
                            <p class="text-xs text-gray-500 uppercase">{{ __('Front') }}</p>
                            <img src="{{ route('id-cards.render.front', $idCard) }}" class="border rounded max-w-xs" alt="{{ __('Card front') }}">
                        </div>
                        <div class="space-y-1">
                            <p class="text-xs text-gray-500 uppercase">{{ __('Back') }}</p>
                            <img src="{{ route('id-cards.render.back', $idCard) }}" class="border rounded max-w-xs" alt="{{ __('Card back') }}">
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{--
        Plain server-driven @if, deliberately not Alpine's x-show — this
        modal's visibility is entirely PHP state ($pendingAction), and
        Livewire's own re-render already includes/excludes this markup on
        every request. An earlier version used x-show bound to a Blade-
        interpolated literal string ("true"/"false"); Alpine compiles an
        x-show expression into a fixed closure at directive-init time and
        never re-parses it just because Livewire's morph later patches the
        raw attribute text — so the modal silently never opened after the
        first page load, confirmed live in a browser (Pest's component
        tests never caught it, since they call stage()/confirmStaged()
        directly and never render or diff real DOM).
    --}}
    @if ($pendingAction)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
            <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full space-y-4">
                <h3 class="text-lg font-medium text-gray-900">
                    @if ($pendingAction === 'lost') {{ __('Mark this card lost?') }}
                    @elseif ($pendingAction === 'revoke') {{ __('Revoke this card?') }}
                    @elseif ($pendingAction === 'expire') {{ __('Expire this card?') }}
                    @endif
                </h3>
                <p class="text-sm text-gray-600">
                    @if ($pendingAction === 'lost')
                        {{ __('A replacement card is issued automatically, with a new control number.') }}
                    @else
                        {{ __('This card cannot be un-revoked or un-expired — a new card would need to be issued separately.') }}
                    @endif
                </p>

                <x-form-field name="reason" :label="__('Reason')">
                    <x-text-input wire:model="reason" id="reason" class="block mt-1 w-full" type="text" />
                </x-form-field>

                <div class="flex justify-end gap-3">
                    <x-secondary-button type="button" wire:click="cancelStaged">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="button" wire:click="confirmStaged">{{ __('Confirm') }}</x-primary-button>
                </div>
            </div>
        </div>
    @endif
</div>
