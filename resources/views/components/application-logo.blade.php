{{--
    The site's logo (Phase 16): the one a Superadmin uploaded in Site
    settings, or — until they do — a neutral ID-card mark, never Laravel's.
    The uploaded logo is square, so the classes a caller passes for the mark
    (w-20 h-20, h-9 w-auto) size it the same way.
--}}
@php
    $branding = \App\Models\SiteSetting::current();
    $logoUrl = $branding->logoUrl();
@endphp

@if ($logoUrl)
    <img src="{{ $logoUrl }}" alt="{{ $branding->siteName() }}" {{ $attributes->merge(['class' => 'object-contain']) }}>
@else
    <svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="{{ $branding->siteName() }}" {{ $attributes }}>
        <rect x="6" y="13" width="52" height="38" rx="6" fill="none" stroke="currentColor" stroke-width="4" />
        <circle cx="22" cy="28" r="6" fill="currentColor" />
        <path d="M12 44c1.5-5 5.5-8 10-8s8.5 3 10 8z" fill="currentColor" />
        <rect x="36" y="25" width="16" height="4" rx="2" fill="currentColor" />
        <rect x="36" y="33" width="12" height="4" rx="2" fill="currentColor" />
    </svg>
@endif
