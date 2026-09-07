@props(['name', 'accept' => 'image/png,image/jpeg', 'outputSize' => 480])

{{--
    A file input that checks the chosen image's own dimensions before it
    ever reaches the server: a 1:1 image uploads straight through
    `$wire.upload()`, same as `<x-camera-capture>` (which is always
    already square by construction, so it never needs this). Anything
    else opens an in-browser crop tool — drag to reposition, the slider to
    resize, always square — and only the cropped result is ever uploaded.
    The original non-square file is never sent to the server at all, which
    is what "discarded" means here: there's nothing server-side to clean
    up because nothing server-side ever received it.
--}}
<div
    x-data="{
        cropping: false,
        img: null,
        naturalW: 0, naturalH: 0,
        displayW: 0, displayH: 0,
        boxX: 0, boxY: 0, boxSize: 0,
        dragging: false, dragOffsetX: 0, dragOffsetY: 0,

        onFileChange(event) {
            const file = event.target.files[0];
            event.target.value = '';
            if (! file) return;
            this.loadImage(file);
        },

        loadImage(file) {
            const url = URL.createObjectURL(file);
            const image = new Image();
            image.onload = () => {
                this.naturalW = image.naturalWidth;
                this.naturalH = image.naturalHeight;

                if (this.naturalW === this.naturalH) {
                    URL.revokeObjectURL(url);
                    this.upload(file);
                    return;
                }

                this.img = image;
                this.openCropper();
            };
            image.src = url;
        },

        openCropper() {
            this.cropping = true;
            this.$nextTick(() => {
                const maxDisplay = 420;
                const ratio = this.naturalW / this.naturalH;

                if (ratio >= 1) {
                    this.displayW = maxDisplay;
                    this.displayH = Math.round(maxDisplay / ratio);
                } else {
                    this.displayH = maxDisplay;
                    this.displayW = Math.round(maxDisplay * ratio);
                }

                this.$refs.cropImg.src = this.img.src;
                this.boxSize = Math.min(this.displayW, this.displayH);
                this.boxX = (this.displayW - this.boxSize) / 2;
                this.boxY = (this.displayH - this.boxSize) / 2;
            });
        },

        startDrag(event) {
            this.dragging = true;
            const rect = this.$refs.cropStage.getBoundingClientRect();
            this.dragOffsetX = event.clientX - rect.left - this.boxX;
            this.dragOffsetY = event.clientY - rect.top - this.boxY;
        },

        onDrag(event) {
            if (! this.dragging) return;
            const rect = this.$refs.cropStage.getBoundingClientRect();
            const x = event.clientX - rect.left - this.dragOffsetX;
            const y = event.clientY - rect.top - this.dragOffsetY;
            this.boxX = Math.max(0, Math.min(this.displayW - this.boxSize, x));
            this.boxY = Math.max(0, Math.min(this.displayH - this.boxSize, y));
        },

        stopDrag() { this.dragging = false; },

        onResize(event) {
            const newSize = Number(event.target.value);
            const centerX = this.boxX + this.boxSize / 2;
            const centerY = this.boxY + this.boxSize / 2;
            this.boxSize = newSize;
            this.boxX = Math.max(0, Math.min(this.displayW - newSize, centerX - newSize / 2));
            this.boxY = Math.max(0, Math.min(this.displayH - newSize, centerY - newSize / 2));
        },

        cancelCrop() {
            this.cropping = false;
            this.img = null;
        },

        confirmCrop() {
            const scale = this.naturalW / this.displayW;
            const sx = this.boxX * scale;
            const sy = this.boxY * scale;
            const ssize = this.boxSize * scale;
            const output = {{ $outputSize }};

            const canvas = this.$refs.cropCanvas;
            canvas.width = output;
            canvas.height = output;
            canvas.getContext('2d').drawImage(this.img, sx, sy, ssize, ssize, 0, 0, output, output);

            canvas.toBlob((blob) => {
                this.upload(new File([blob], 'cropped.jpg', { type: 'image/jpeg' }));
                this.cropping = false;
                this.img = null;
            }, 'image/jpeg', 0.85);
        },

        upload(file) {
            this.$wire.upload('{{ $name }}', file);
        },
    }"
>
    <input type="file" x-on:change="onFileChange" accept="{{ $accept }}">

    <div
        x-show="cropping"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
        style="display: none;"
    >
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-lg w-full space-y-4">
            <h3 class="text-lg font-medium text-gray-900">{{ __('Crop photo') }}</h3>
            <p class="text-sm text-gray-500">{{ __('This photo isn\'t square. Drag the box to choose what to keep, resize with the slider below, then crop.') }}</p>

            <div
                x-ref="cropStage"
                class="relative mx-auto select-none touch-none"
                x-bind:style="`width: ${displayW}px; height: ${displayH}px;`"
                x-on:pointermove.window="onDrag"
                x-on:pointerup.window="stopDrag"
            >
                <img x-ref="cropImg" class="absolute inset-0 w-full h-full pointer-events-none" alt="">
                <div
                    x-on:pointerdown="startDrag"
                    class="absolute border-2 border-white cursor-move"
                    x-bind:style="`left: ${boxX}px; top: ${boxY}px; width: ${boxSize}px; height: ${boxSize}px; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5);`"
                ></div>
            </div>

            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('Crop size') }}</label>
                <input type="range" x-on:input="onResize" x-bind:value="boxSize" x-bind:max="Math.min(displayW, displayH)" min="40" class="w-full">
            </div>

            <canvas x-ref="cropCanvas" class="hidden"></canvas>

            <div class="flex justify-end gap-3">
                <x-secondary-button type="button" x-on:click="cancelCrop">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" x-on:click="confirmCrop">{{ __('Crop & use') }}</x-primary-button>
            </div>
        </div>
    </div>
</div>
