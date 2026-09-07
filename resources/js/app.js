import { Html5Qrcode } from 'html5-qrcode';

// Bundled at build time, not fetched from a CDN — this system is LAN-only,
// never public (CLAUDE.md). Exposed as a global so <x-qr-scanner>'s plain
// inline Alpine script can use it without every Blade component needing
// its own Vite entry point.
window.Html5Qrcode = Html5Qrcode;
