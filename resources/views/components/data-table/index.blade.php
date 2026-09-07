@props(['paginator' => null])

{{--
    The List/Index pattern's shell (docs/design/wireframes.md). The calling
    screen supplies its own <thead> via the `head` slot (built from
    <x-data-table.sort-header> cells) and its own <tbody> content as the
    default slot — this component owns only the table chrome, pagination,
    and horizontal-overflow handling, never the columns themselves, since
    those are different for every screen that uses it.
--}}
<div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        @isset($head)
            <thead>
                <tr class="border-b">
                    {{ $head }}
                </tr>
            </thead>
        @endisset

        <tbody>
            {{ $slot }}
        </tbody>
    </table>

    @if ($paginator)
        <div class="mt-4">
            {{ $paginator->links() }}
        </div>
    @endif
</div>
