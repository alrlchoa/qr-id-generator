@props(['onDecode' => 'scan'])

{{--
    Wraps html5-qrcode (resources/js/app.js exposes it as window.Html5Qrcode
    — bundled at build time, never fetched from a CDN, since this system is
    LAN-only and never public). On a successful decode it calls the named
    Livewire method with the raw decoded text and stops the camera; the
    Livewire side is responsible for normalizing/validating that text, this
    component only relays it.

    Same secure-context caveat as <x-camera-capture>: getUserMedia needs
    HTTPS or localhost.
--}}
<div
    x-data="{
        active: false,
        error: null,
        scanner: null,
        async start() {
            this.error = null;

            if (! navigator.mediaDevices?.getUserMedia) {
                this.error = 'This browser (or connection) does not support camera access. HTTPS is required outside localhost.';
                return;
            }

            if (typeof Html5Qrcode === 'undefined') {
                this.error = 'The QR scanning library failed to load.';
                return;
            }

            this.scanner = new Html5Qrcode('qr-reader-region');

            try {
                await this.scanner.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: 250 },
                    (decodedText) => {
                        this.$wire.call('{{ $onDecode }}', decodedText);
                        this.stop();
                    },
                    () => {},
                );
                this.active = true;
            } catch (e) {
                this.error = 'Could not access the camera: ' + e.message;
            }
        },
        async stop() {
            if (this.scanner && this.active) {
                try { await this.scanner.stop(); } catch (e) {}
            }
            this.active = false;
        },
    }"
    x-on:keydown.escape.window="stop()"
    class="space-y-2"
>
    <div class="flex flex-wrap gap-2">
        <x-secondary-button type="button" x-show="!active" x-on:click="start" style="display: none;">
            {{ __('Start scanning') }}
        </x-secondary-button>
        <x-secondary-button type="button" x-show="active" x-on:click="stop" style="display: none;">
            {{ __('Stop scanning') }}
        </x-secondary-button>
    </div>

    <p x-show="error" x-text="error" class="text-sm text-red-600" style="display: none;"></p>

    <div id="qr-reader-region" x-show="active" class="w-full max-w-sm" style="display: none;"></div>
</div>
