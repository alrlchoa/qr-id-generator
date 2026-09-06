<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <livewire:profile.update-profile-information-form />
                </div>
            </div>

            {{--
                No self-service password change here, deliberately. A
                password changes in exactly two ways in this system
                (CLAUDE.md): the mandatory rotation a Superadmin-issued
                temporary password forces at next login, and a Superadmin
                resetting another account from the Users screen. A third,
                voluntary path — someone changing a password they already
                know, at will — was removed rather than kept alongside those
                two, so there is one place password changes are visible and
                one audit shape to reason about, not three.
            --}}
        </div>
    </div>
</x-app-layout>
