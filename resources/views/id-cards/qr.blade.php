<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('QR — Card #:number', ['number' => $idCard->control_number]) }}
        </h2>
    </x-slot>

    <div class="py-12 print:py-0">
        <div class="max-w-md mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg print:shadow-none text-center">
                <div class="inline-block">{!! $svg !!}</div>
                <p class="mt-4 font-mono text-lg">{{ $idCard->control_number }}</p>
                <p class="text-sm text-gray-500">{{ ucfirst($idCard->type) }}@if ($idCard->unit), {{ __('Unit :code', ['code' => $idCard->unit->unitCode()]) }}@endif</p>

                <button type="button" onclick="window.print()" class="print:hidden mt-6 inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    {{ __('Print') }}
                </button>
            </div>
        </div>
    </div>
</x-app-layout>
