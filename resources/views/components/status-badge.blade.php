@props(['status'])

{{--
    The one status badge, designed once (Phase 5 plan) for the five values
    id_cards.status is ever CHECK-constrained to (architecture §3/§4).
    `replaced` is deliberately not styled as a failure — it's an ordinary
    lifecycle transition, not something gone wrong, unlike lost/revoked.
--}}
@php
$styles = [
    'active' => 'bg-green-100 text-green-800',
    'lost' => 'bg-amber-100 text-amber-800',
    'revoked' => 'bg-red-100 text-red-800',
    'expired' => 'bg-gray-200 text-gray-700',
    'replaced' => 'bg-blue-100 text-blue-800',
][$status] ?? 'bg-gray-100 text-gray-600';
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {$styles}"]) }}>
    {{ ucfirst($status) }}
</span>
