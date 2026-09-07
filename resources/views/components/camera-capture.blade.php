@props(['name' => 'photo'])

{{--
    Captures a square photo from a connected camera (webcam or otherwise)
    and uploads it into the named Livewire property via `$wire.upload()` —
    the same JS-facing entry point Livewire's own file input uses, so a
    captured frame and a picked file are indistinguishable to the server:
    both arrive as a `TemporaryUploadedFile` on `$name`, validated and
    stored by whatever the page already does with that property.

    getUserMedia() requires a secure context (HTTPS, or `localhost`) — on a
    plain-HTTP LAN address the button will fail with a clear error rather
    than silently doing nothing.

    The live preview is mirrored (CSS only) so framing a shot feels
    natural — like looking in a mirror, the way every phone/webcam camera
    app previews a front-facing camera. The captured photo is never
    mirrored: `capture()` reads pixels via `drawImage(video, ...)`, which
    always draws the video's raw underlying frame regardless of any CSS
    transform applied to the `<video>` element for display — so an ID
    photo never comes out with hair parted on the wrong side or backward
    text on clothing, even though the preview above it looks flipped.
--}}
<div
    x-data="{
        active: false,
        busy: false,
        error: null,
        stream: null,
        async start() {
            this.error = null;

            if (! navigator.mediaDevices?.getUserMedia) {
                this.error = 'This browser (or connection) does not support camera access. HTTPS is required outside localhost.';
                return;
            }

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false });
                this.$refs.video.srcObject = this.stream;
                this.active = true;
            } catch (e) {
                this.error = 'Could not access the camera: ' + e.message;
            }
        },
        stop() {
            this.stream?.getTracks().forEach(track => track.stop());
            this.stream = null;
            this.active = false;
        },
        capture() {
            const video = this.$refs.video;
            const canvas = this.$refs.canvas;
            const side = Math.min(video.videoWidth, video.videoHeight);

            canvas.width = side;
            canvas.height = side;
            canvas.getContext('2d').drawImage(
                video,
                (video.videoWidth - side) / 2, (video.videoHeight - side) / 2, side, side,
                0, 0, side, side,
            );

            this.busy = true;

            canvas.toBlob((blob) => {
                const file = new File([blob], 'camera-capture.jpg', { type: 'image/jpeg' });

                this.$wire.upload('{{ $name }}', file,
                    () => { this.busy = false; this.stop(); },
                    () => { this.busy = false; this.error = 'Upload failed — try again.'; },
                );
            }, 'image/jpeg', 0.85);
        },
    }"
    x-on:keydown.escape.window="stop()"
    class="space-y-2"
>
    <div class="flex flex-wrap gap-2">
        <x-secondary-button type="button" x-show="!active" x-on:click="start" style="display: none;">
            {{ __('Take a photo') }}
        </x-secondary-button>
        <x-secondary-button type="button" x-show="active" x-on:click="capture" x-bind:disabled="busy" style="display: none;">
            <span x-show="!busy">{{ __('Capture') }}</span>
            <span x-show="busy" style="display: none;">{{ __('Uploading…') }}</span>
        </x-secondary-button>
        <x-secondary-button type="button" x-show="active" x-on:click="stop" style="display: none;">
            {{ __('Cancel') }}
        </x-secondary-button>
    </div>

    <p x-show="error" x-text="error" class="text-sm text-red-600" style="display: none;"></p>

    <video x-ref="video" x-show="active" autoplay playsinline muted class="w-48 h-48 shrink-0 object-cover rounded-md border bg-black -scale-x-100" style="display: none;"></video>
    <canvas x-ref="canvas" class="hidden"></canvas>
</div>
