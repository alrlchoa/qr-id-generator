<?php

use App\Exceptions\TemplateFieldObscuredException;
use App\Exceptions\TemplateOverlayObscuresQrException;
use App\Models\Font;
use App\Models\Template;
use App\Services\QrCodeGenerator;
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

    /**
     * A dummy control number, never a real one — architecture never assigns
     * a genuine card the all-zero number (`randomEightDigits()` is drawn
     * from `random_int`, and a zero-collision would be astronomically rare
     * even if it weren't). Lets a Superadmin scan the editor's own preview
     * QR with the real Scan page and see it correctly resolve to Verify
     * ("no card found") — proving the QR itself is physically readable —
     * without any fixture row ever touching `id_cards` (rule 7: those rows
     * are permanent, so a fake one would never go away).
     */
    public string $qrPreviewCode = '00000000';

    public function mount(Template $template): void
    {
        $this->template = $template;
        $this->authorize('view', $template);
        $this->seedPositions();
    }

    /**
     * Every placeable field starts centered horizontally and stacked
     * top-to-bottom in reading order if the template doesn't already have
     * a saved position for it — the editor always has something usable to
     * drag on a brand-new template, not an arbitrary diagonal scatter.
     */
    private function seedPositions(): void
    {
        $saved = $this->template->field_positions_front ?? [];
        $canvasWidth = $this->template->width_px;

        // width/height first, per field — x/y (the centered default) is
        // computed from them below, never the reverse.
        $defaultSizes = [
            'photo' => ['width' => 150, 'height' => 150],
            'name' => ['width' => 420, 'height' => 40],
            'unit_number' => ['width' => 260, 'height' => 32],
            'role' => ['width' => 260, 'height' => 30],
            'qr' => ['width' => 150, 'height' => 150],
        ];

        $positions = [];
        $y = 30;

        foreach ($this->template->placeableFields() as $field) {
            $size = $defaultSizes[$field];

            $positions[$field] = $saved[$field] ?? [
                'x' => intdiv($canvasWidth - $size['width'], 2),
                'y' => $y,
                'width' => $size['width'],
                'height' => $size['height'],
            ];

            $y += $size['height'] + 20;
        }

        $this->positions = $positions;
        $this->positionsJson = json_encode($positions) ?: '{}';
    }

    /**
     * Realistic filler text for the fields this page doesn't offer its own
     * editable preview control for — "A0101" reads as an actual card at a
     * glance in a way the bare field name ("unit_number") never did.
     * `name` and `role` are driven by the editor's own "sample data for
     * preview" panel instead (Alpine's `previewName`/`previewRoleType`) —
     * see the field-placement editor below.
     *
     * @return array<string, string>
     */
    public function fieldFillerText(): array
    {
        return [
            'unit_number' => 'A0101',
        ];
    }

    /**
     * A real QR, encoding whatever `$qrPreviewCode` currently holds —
     * '00000000' by default — rendered by the identical `QrCodeGenerator`
     * `CardRenderer` uses for a real card, so what a Superadmin sees (and
     * can physically scan) here is exactly what production output looks
     * like. SVG rather than the raster GD path: this never touches a
     * canvas, so there's no reason to pull in GD for what the browser can
     * just display directly.
     */
    public function qrPreviewDataUri(): string
    {
        $code = $this->qrPreviewCode !== '' ? $this->qrPreviewCode : '00000000';
        $svg = app(QrCodeGenerator::class)->svgFor($code, 300);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The active font's authenticated URL (`FontFileController`), or null
     * when no font is active — a normal state (`Font::activeFont()`'s own
     * contract), rendered as plain browser-default text in that case,
     * matching `CardRenderer`'s own GD-built-in fallback.
     */
    public function activeFontUrl(): ?string
    {
        $font = Font::activeFont();

        return $font !== null ? route('fonts.file', $font) : null;
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
        } catch (InvalidArgumentException $e) {
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
        } catch (InvalidArgumentException $e) {
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

        // Livewire only clears an addError()'d field automatically when a
        // validate() call for that same key succeeds — this method never
        // calls validate() (positions come from raw JS, validated entirely
        // by TemplateManager), so a previous failure's message would
        // otherwise sit in the error bag forever, including through a
        // later successful save. Reset explicitly before trying again, so
        // a second pass either shows nothing (success) or exactly the new
        // failure, never last time's stale message.
        $this->resetErrorBag('positions');

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
        } catch (InvalidArgumentException $e) {
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

        // See savePositions()'s own note — no validate() call happens
        // here either, so a stale error from a previous attempt would
        // otherwise never clear on a later success.
        $this->resetErrorBag('activate');

        try {
            $templates->activate(auth()->user(), $this->template);
        } catch (InvalidArgumentException $e) {
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

        // See savePositions()'s own note.
        $this->resetErrorBag('delete');

        try {
            $templates->delete(auth()->user(), $this->template);
        } catch (InvalidArgumentException $e) {
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
        fillerText: {{ Illuminate\Support\Js::from($this->fieldFillerText()) }},
        roleOptions: {{ Illuminate\Support\Js::from(['owner' => 'Unit Owner', 'tenant' => 'Tenant', 'employee' => 'Employee']) }},
        previewRoleType: {{ Illuminate\Support\Js::from($template->id_type) }},
        previewName: 'John Doe',
        previewMode: false,
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
            if (this.previewMode) return;
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
                @if ($fontUrl = $this->activeFontUrl())
                    {{-- The exact font CardRenderer draws a real card with —
                         see FontFileController's own note on why this is a
                         route, not a base64 embed like the overlay preview
                         above (a font can run several MB). --}}
                    <style>
                        @font-face {
                            font-family: 'CardPreviewFont';
                            src: url('{{ $fontUrl }}') format('truetype');
                        }
                        .card-preview-text { font-family: 'CardPreviewFont', sans-serif; }
                    </style>
                @endif

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-medium">{{ __('Field placement') }}</h3>
                        <div class="flex gap-2">
                            <x-secondary-button type="button" x-on:click="previewMode = !previewMode">
                                <span x-text="previewMode ? '{{ __('Edit') }}' : '{{ __('Preview') }}'"></span>
                            </x-secondary-button>
                            <x-primary-button type="button" x-on:click="save" x-show="!previewMode">{{ __('Save positions') }}</x-primary-button>
                        </div>
                    </div>
                    <x-input-error :messages="$errors->get('positions')" class="mt-1" />

                    {{-- Sample data driving the preview below — never persisted,
                         never touches a real Person/IdCard row. The QR code
                         defaults to a dummy '00000000' (rule 14's char(8) shape)
                         precisely because it is never a real control number
                         (see $qrPreviewCode's own note): safe to scan with the
                         real Scan page as a pure readability test. --}}
                    <div class="border rounded-lg p-3 space-y-3 bg-gray-50">
                        <h4 class="text-xs font-semibold uppercase text-gray-500">{{ __('Sample data for preview') }}</h4>
                        <div class="grid sm:grid-cols-3 gap-3 text-xs">
                            <label class="flex flex-col gap-1">
                                {{ __('Sample name') }}
                                <input type="text" maxlength="60" class="border rounded px-2 py-1" x-model="previewName">
                            </label>
                            <label class="flex flex-col gap-1">
                                {{ __('Sample role') }}
                                <select class="border rounded px-2 py-1" x-model="previewRoleType">
                                    <option value="owner">{{ __('Unit Owner') }}</option>
                                    <option value="tenant">{{ __('Tenant') }}</option>
                                    <option value="employee">{{ __('Employee') }}</option>
                                </select>
                            </label>
                            <label class="flex flex-col gap-1">
                                {{ __('Test QR code (8 digits)') }}
                                <input type="text" maxlength="8" inputmode="numeric" pattern="[0-9]{8}" class="border rounded px-2 py-1 font-mono" wire:model.blur="qrPreviewCode">
                            </label>
                        </div>
                        <p class="text-xs text-gray-500">{{ __('00000000 is a safe dummy code, never a real control number — scan it with the Scan page to confirm the QR itself is readable. Verify will correctly report it as not found; that\'s expected, and proves nothing real was touched.') }}</p>
                    </div>

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
                                x-bind:class="{
                                    'cursor-move': !previewMode,
                                    'border-2 border-indigo-600 bg-indigo-100/40': !previewMode && selected === field,
                                    'border-2 border-dashed border-gray-400 bg-white/40': !previewMode && selected !== field,
                                }"
                                class="absolute overflow-hidden flex items-center justify-center"
                            >
                                {{-- Realistic filler per field, not the bare field key — a "John
                                     Doe"/"A0101" preview reads as an actual card at a glance.
                                     Photo stays a placeholder icon (no person exists yet to have
                                     one); QR is the real, scannable raster from the sample panel
                                     above, generated by the same QrCodeGenerator a real card uses. --}}
                                <template x-if="field === 'photo'">
                                    <svg viewBox="0 0 100 100" class="w-3/5 h-3/5 text-gray-400" fill="currentColor">
                                        <circle cx="50" cy="36" r="20" />
                                        <path d="M50 62c-24 0-38 14-38 30v8h76v-8c0-16-14-30-38-30z" />
                                    </svg>
                                </template>
                                <template x-if="field === 'qr'">
                                    <img src="{{ $this->qrPreviewDataUri() }}" class="w-4/5 h-4/5" alt="{{ __('Scannable QR preview') }}">
                                </template>
                                <template x-if="field !== 'photo' && field !== 'qr'">
                                    <span
                                        class="px-1 text-gray-700 truncate card-preview-text"
                                        x-bind:style="`font-size: ${Math.max(10, toDisplay(box.height) * 0.55)}px;`"
                                        x-text="field === 'name' ? previewName : (field === 'role' ? roleOptions[previewRoleType] : (fillerText[field] ?? field))"
                                    ></span>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="grid sm:grid-cols-2 gap-4" x-show="!previewMode">
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

    {{--
        Plain server-driven @if, not Alpine's x-show bound to a Blade
        literal — see id-cards/show.blade.php's identical fix for why:
        Alpine compiles x-show into a fixed closure at init time and never
        re-parses it just because Livewire's morph patches the raw
        attribute text later, so this dialog never actually opened.
    --}}
    @if ($pendingConfirm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
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
    @endif
</div>
