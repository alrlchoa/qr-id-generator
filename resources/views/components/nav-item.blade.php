@props(['route', 'label', 'mobile' => false, 'dark' => false])

{{--
    One role-gated nav link, desktop or mobile variant from the same call
    site (Phase 5 plan: "navigation structure"). The role check itself
    stays in navigation.blade.php, wrapped around this component — this
    renders whatever it's given, it doesn't decide who sees it. Hiding a
    nav item is UX; the Policy on the route is the actual boundary
    (architecture §11) and is unaffected by whether this renders at all.

    `dark` (Phase 16): the navbar's colour is dark, so the link text is
    light. navigation.blade.php works that out once and passes it to every
    link.
--}}
<x-dynamic-component
    :component="$mobile ? 'responsive-nav-link' : 'nav-link'"
    :href="route($route)"
    :active="request()->routeIs($route)"
    :dark="$dark"
    wire:navigate
>
    {{ $label }}
</x-dynamic-component>
