@props(['colspan' => 1])

{{--
    The table's one empty state, designed once here rather than improvised
    per screen (Phase 5 plan). Architecture §14 makes the same point about
    the reconciliation dashboard specifically: empty is a normal, expected
    state, not an error — this deliberately does not look alarming.
--}}
<tr>
    <td colspan="{{ $colspan }}" class="py-6 text-center text-gray-500">
        {{ $slot->isEmpty() ? __('Nothing here.') : $slot }}
    </td>
</tr>
