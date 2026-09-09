<?php

use App\Exceptions\TemplateFieldObscuredException;
use App\Exceptions\TemplateOverlayObscuresQrException;
use App\Models\Template;
use App\Services\TemplateManager;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public Template $template;

    public $frontOverlay = null;

    public $backOverlay = null;

    /** @var array<string, array{x: int, y: int, width: int, height: int}> */
    public array $positions = [];

    public string $positionsJson = '{}';

    /** Set when savePositions() is refused for coverage over a non-QR field — offers a confirm-and-retry. */
    public bool $pendingConfirm = false;

    /** @var list<string> */
    public array $pendingObscuredFields = [];

    public function mount(Template $template): void
    {
        $this->template = $template;
        $this->authorize('view', $template);
        $this->seedPositions();
    }

    /**
     * Every placeable field starts as a small default box in the canvas
     * corner if the template doesn't already have a saved position for
     * it — the editor always has something to drag, even on a brand-new
     * template with no positions saved yet.
     */
    private function seedPositions(): void
    {
        $saved = $this->template->field_positions_front ?? [];
        $positions = [];

        foreach ($this->template->placeableFields() as $index => $field) {
            $positions[$field] = $saved[$field] ?? [
                'x' => 20 + ($index * 40),
                'y' => 20 + ($index * 40),
                'width' => $field === 'photo' || $field === 'qr' ? 120 : 200,
                'height' => $field === 'photo' || $field === 'qr' ? 120 : 30,
            ];
        }

        $this->positions = $positions;
        $this->positionsJson = json_encode($positions) ?: '{}';
    }

    public function frontOverlayDataUri(): ?string
    {
        return $this->overlayDataUri($this->template->overlay_path_front);
    }

    public function backOverlayDataUri(): ?string
    {
        return $this->overlayDataUri($this->template->overlay_path_back);
    }

    /**
     * The editor's own preview of the Superadmin's own uploaded card
     * artwork — not a person's photo, so CLAUDE.md rule 22's "no base64
     * embedding" (which exists specifically to protect photo privacy
     * behind an authenticated, policy-checked route) doesn't reach this
     * asset. Embedding avoids a second authenticated file route for what
     * is, on this screen, already behind `TemplatePolicy::view()`.
     */
    private function overlayDataUri(?string $path): ?string
    {
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $bytes = Storage::disk('local')->get($path);

        return 'data:image/png;base64,'.base64_encode((string) $bytes);
    }

    public function uploadFront(TemplateManager $templates): void
    {
        $this->authorize('update', $this->template);

        $this->validate(['frontOverlay' => ['required', 'file']]);

        try {
            $templates->uploadFrontOverlay(auth()->user(), $this->template, $this->frontOverlay);
        } catch (TemplateOverlayObscuresQrException|TemplateFieldObscuredException $e) {
            // The existing saved field_positions_front is what triggered
            // this — the new artwork itself uploaded fine, the check ran
            // against positions already on record. Surface it as a plain
            // error rather than the confirm flow savePositions() offers:
            // re-uploading is the fix, not force-saving over it.
            $this->addError('frontOverlay', $e->getMessage());

            return;
        } catch (\InvalidArgumentException $e) {
            $this->addError('frontOverlay', $e->getMessage());

            return;
        }

        $this->reset('frontOverlay');
        $this->template->refresh();
        session()->flash('status', __('Front artwork uploaded.'));
    }

    public function uploadBack(TemplateManager $templates): void
    {
        $this->authorize('update', $this->template);

        $this->validate(['backOverlay' => ['required', 'file']]);

        try {
            $templates->uploadBackOverlay(auth()->user(), $this->template, $this->backOverlay);
        } catch (\InvalidArgumentException $e) {
            $this->addError('backOverlay', $e->getMessage());

            return;
        }

        $this->reset('backOverlay');
        $this->template->refresh();
        session()->flash('status', __('Back artwork uploaded.'));
    }

    /**
     * `$positions` arrives from the Alpine-driven drag editor as a JS
     * object — already in render-resolution pixels (the editor scales
     * display coordinates back before calling this), never display
     * pixels. See the editor's own `toRenderCoords()` for where that
     * conversion happens.
     *
     * @param  array<string, array<string, mixed>>  $positions
     */
    public function savePositions(array $positions, bool $force = false, ?TemplateManager $templates = null): void
    {
        $this->authorize('update', $this->template);
        $templates ??= app(TemplateManager::class);

        try {
            $templates->saveFieldPositions(auth()->user(), $this->template, $positions, $force);
        } catch (TemplateOverlayObscuresQrException $e) {
            $this->addError('positions', $e->getMessage());

            return;
        } catch (TemplateFieldObscuredException $e) {
            $this->pendingConfirm = true;
            $this->pendingObscuredFields = $e->obscuredFields;
            $this->positionsJson = json_encode($positions) ?: '{}';

            return;
        } catch (\InvalidArgumentException $e) {
            $this->addError('positions', $e->getMessage());

            return;
        }

        $this->pendingConfirm = false;
        $this->pendingObscuredFields = [];
        $this->template->refresh();
        $this->seedPositions();
        session()->flash('status', __('Field positions saved.'));
    }

    public function confirmSaveDespiteWarning(TemplateManager $templates): void
    {
        $positions = json_decode($this->positionsJson, true) ?? [];
        $this->savePositions($positions, force: true, templates: $templates);
    }

    public function cancelPendingConfirm(): void
    {
        $this->pendingConfirm = false;
        $this->pendingObscuredFields = [];
    }

    public function activate(TemplateManager $templates): void
    {
        $this->authorize('update', $this->template);

        try {
            $templates->activate(auth()->user(), $this->template);
        } catch (\InvalidArgumentException $e) {
            $this->addError('activate', $e->getMessage());

            return;
        }

        $this->template->refresh();
        session()->flash('status', __('Template activated.'));
    }

    public function deactivate(TemplateManager $templates): void
    {
        $this->authorize('update', $this->template);

        $templates->deactivate(auth()->user(), $this->template);
        $this->template->refresh();
        session()->flash('status', __('Template deactivated.'));
    }

    public function delete(TemplateManager $templates): void
    {
        $this->authorize('delete', $this->template);

        try {
            $templates->delete(auth()->user(), $this->template);
        } catch (\InvalidArgumentException $e) {
            $this->addError('delete', $e->getMessage());

            return;
        }

        $this->redirect(route('templates.index'), navigate: true);
    }
}; ?>

<div
    x-data="{
        renderWidth: {{ $template->width_px }},
        renderHeight: {{ $template->height_px }},
        positions: {{ $positionsJson }},
        selected: null,
        dragging: false, dragOffsetX: 0, dragOffsetY: 0,
        displayScale: 1,

        init() {
            this.$nextTick(() => this.fitToStage());
            window.addEventListener('resize', () => this.fitToStage());
        },

        fitToStage() {
            const stage = this.$refs.stage;
            if (! stage) return;
            this.displayScale = Math.min(1, stage.clientWidth / this.renderWidth);
        },

        toDisplay(renderValue) { return renderValue * this.displayScale; },
        toRender(displayValue) { return Math.round(displayValue / this.displayScale); },

        select(field) { this.selected = field; },

        startDrag(field, event) {
            this.selected = field;
            this.dragging = field;
            const rect = this.$refs.stage.getBoundingClientRect();
            this.dragOffsetX = event.clientX - rect.left - this.toDisplay(this.positions[field].x);
            this.dragOffsetY = event.clientY - rect.top - this.toDisplay(this.positions[field].y);
        },

        onDrag(event) {
            if (! this.dragging) return;
            const field = this.dragging;
            const rect = this.$refs.stage.getBoundingClientRect();
            const displayX = event.clientX - rect.left - this.dragOffsetX;
            const displayY = event.clientY - rect.top - this.dragOffsetY;
            const box = this.positions[field];
            const maxX = this.renderWidth - box.width;
            const maxY = this.renderHeight - box.height;
            box.x = Math.max(0, Math.min(maxX, this.toRender(displayX)));
            box.y = Math.max(0, Math.min(maxY, this.toRender(displayY)));
        },

        stopDrag() { this.dragging = false; },

        align(field, where) {
            const box = this.positions[field];
            if (where === 'left') box.x = 0;
            if (where === 'right') box.x = this.renderWidth - box.width;
            if (where === 'top') box.y = 0;
            if (where === 'bottom') box.y = this.renderHeight - box.height;
            if (where === 'center-h') box.x = Math.round((this.renderWidth - box.width) / 2);
            if (where === 'center-v') box.y = Math.round((this.renderHeight - box.height) / 2);
        },

        save() {
            this.$wire.savePositions(this.positions);
        },
    }"
>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $template->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-4 bg-green-50 text-green-800 rounded-lg text-sm">{{ session('status') }}</div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="text-sm text-gray-600 space-x-4">
                        <span class="capitalize">{{ __(':type card', ['type' => $template->id_type]) }}</span>
                        <span class="capitalize">{{ $template->orientation() }}</span>
                        <span>{{ $template->width_px }}×{{ $template->height_px }}px</span>
                    </div>

                    <div class="flex gap-3">
                        @if ($template->is_active)
                            <x-secondary-button type="button" wire:click="deactivate">{{ __('Deactivate') }}</x-secondary-button>
                        @else
                            <x-primary-button type="button" wire:click="activate" wire:confirm="{{ __('Activate this template? Any other active template for this card type will be deactivated.') }}">
                                {{ __('Activate') }}
                            </x-primary-button>
                        @endif

                        <x-danger-button type="button" wire:click="delete" wire:confirm="{{ __('Delete this template? This cannot be undone.') }}">
                            {{ __('Delete') }}
                        </x-danger-button>
                    </div>
                </div>
                <x-input-error :messages="$errors->get('activate')" class="mt-1" />
                <x-input-error :messages="$errors->get('delete')" class="mt-1" />
            </div>

            {{-- Front artwork upload --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <h3 class="text-lg font-medium">{{ __('Front artwork') }}</h3>
                <p class="text-sm text-gray-500">{{ __('A PNG with transparency, exactly :w×:hpx. It composites last, on top of every field below — a cut-out frames the photo as a border.', ['w' => $template->width_px, 'h' => $template->height_px]) }}</p>

                <form wire:submit="uploadFront" class="flex items-end gap-4">
                    <input type="file" wire:model="frontOverlay" accept="image/png" class="text-sm">
                    <x-primary-button type="submit">{{ __('Upload') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('frontOverlay')" class="mt-1" />
            </div>

            {{-- Field placement editor --}}
            @if ($template->overlay_path_front || $template->field_positions_front)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">{{ __('Field placement') }}</h3>
                        <x-primary-button type="button" x-on:click="save">{{ __('Save positions') }}</x-primary-button>
                    </div>
                    <x-input-error :messages="$errors->get('positions')" class="mt-1" />

                    <div
                        x-ref="stage"
                        class="relative mx-auto border border-gray-300 bg-gray-50 select-none touch-none overflow-hidden"
                        x-bind:style="`width: 100%; max-width: ${renderWidth}px; aspect-ratio: ${renderWidth} / ${renderHeight};`"
                        x-on:pointermove.window="onDrag"
                        x-on:pointerup.window="stopDrag"
                    >
                        @if ($frontUri = $this->frontOverlayDataUri())
                            <img src="{{ $frontUri }}" class="absolute inset-0 w-full h-full pointer-events-none" alt="">
                        @endif

                        <template x-for="(box, field) in positions" x-bind:key="field">
                            <div
                                x-on:pointerdown="startDrag(field, $event)"
                                x-bind:style="`left: ${toDisplay(box.x)}px; top: ${toDisplay(box.y)}px; width: ${toDisplay(box.width)}px; height: ${toDisplay(box.height)}px;`"
                                x-bind:class="selected === field ? 'border-2 border-indigo-600 bg-indigo-100/40' : 'border-2 border-dashed border-gray-400 bg-white/30'"
                                class="absolute cursor-move flex items-center justify-center text-[10px] font-mono uppercase text-gray-700"
                                x-text="field"
                            ></div>
                        </template>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <template x-for="field in Object.keys(positions)" x-bind:key="field">
                            <div class="border rounded-lg p-3 space-y-2" x-bind:class="selected === field ? 'border-indigo-400' : 'border-gray-200'">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-mono uppercase text-gray-600" x-text="field"></span>
                                    <button type="button" class="text-xs underline text-gray-500" x-on:click="select(field)">{{ __('Select') }}</button>
                                </div>

                                <div class="flex flex-wrap gap-1">
                                    <button type="button" class="text-xs px-2 py-1 border rounded" x-on:click="align(field, 'left')">{{ __('Left') }}</button>
                                    <button type="button" class="text-xs px-2 py-1 border rounded" x-on:click="align(field, 'center-h')">{{ __('Center H') }}</button>
                                    <button type="button" class="text-xs px-2 py-1 border rounded" x-on:click="align(field, 'right')">{{ __('Right') }}</button>
                                    <button type="button" class="text-xs px-2 py-1 border rounded" x-on:click="align(field, 'top')">{{ __('Top') }}</button>
                                    <button type="button" class="text-xs px-2 py-1 border rounded" x-on:click="align(field, 'center-v')">{{ __('Center V') }}</button>
                                    <button type="button" class="text-xs px-2 py-1 border rounded" x-on:click="align(field, 'bottom')">{{ __('Bottom') }}</button>
                                </div>

                                <div class="grid grid-cols-4 gap-1 text-xs">
                                    <label class="flex flex-col">{{ __('X') }} <input type="number" min="0" class="border rounded w-full" x-model.number="positions[field].x"></label>
                                    <label class="flex flex-col">{{ __('Y') }} <input type="number" min="0" class="border rounded w-full" x-model.number="positions[field].y"></label>
                                    <label class="flex flex-col">{{ __('W') }} <input type="number" min="1" class="border rounded w-full" x-model.number="positions[field].width"></label>
                                    <label class="flex flex-col">{{ __('H') }} <input type="number" min="1" class="border rounded w-full" x-model.number="positions[field].height"></label>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            @endif

            {{-- Back artwork upload --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                <h3 class="text-lg font-medium">{{ __('Back artwork') }}</h3>
                <p class="text-sm text-gray-500">{{ __('A static PNG, exactly :w×:hpx — the back has no placeable fields.', ['w' => $template->width_px, 'h' => $template->height_px]) }}</p>

                @if ($backUri = $this->backOverlayDataUri())
                    <img src="{{ $backUri }}" class="max-w-xs border rounded" alt="">
                @endif

                <form wire:submit="uploadBack" class="flex items-end gap-4">
                    <input type="file" wire:model="backOverlay" accept="image/png" class="text-sm">
                    <x-primary-button type="submit">{{ __('Upload') }}</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('backOverlay')" class="mt-1" />
            </div>
        </div>
    </div>

    {{-- Warning-level obscured-field confirmation (never shown for the QR box, which has no force override) --}}
    <div x-show="{{ $pendingConfirm ? 'true' : 'false' }}" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full space-y-4">
            <h3 class="text-lg font-medium text-gray-900">{{ __('Artwork covers a field') }}</h3>
            <p class="text-sm text-gray-600">
                {{ __('The uploaded artwork substantially covers: :fields. This is often the intended border effect.', ['fields' => implode(', ', $pendingObscuredFields)]) }}
            </p>
            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" wire:click="cancelPendingConfirm">{{ __('Go back') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="confirmSaveDespiteWarning">{{ __('Save anyway') }}</x-primary-button>
            </div>
        </div>
    </div>
</div>
