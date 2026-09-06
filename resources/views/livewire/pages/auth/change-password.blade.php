<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Rotate the password and clear the flag that forced this page.
     *
     * No current-password check here — unlike the (now-removed) self-service
     * change on the profile page, arriving at this form already proves
     * possession of the account: either a Superadmin-issued temporary
     * password just authenticated this session, or the wizard's own choice
     * did (architecture §12, both paths set must_change_password from a
     * password the user did not pick themselves). Asking them to re-type
     * the one-time password back to us verifies nothing an authenticated
     * session doesn't already guarantee.
     */
    public function changePassword(Logout $logoutAction): void
    {
        try {
            $validated = $this->validate([
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        // Logged out deliberately, rather than continuing on to the
        // dashboard: this is the first real proof the new password works,
        // and confirming it now — by using it to log back in — is worth the
        // one extra step.
        $logoutAction();

        Session::flash('status', __('Your password has been changed. Sign in with your new password.'));

        $this->redirect(route('login', absolute: false), navigate: true);
    }

    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-gray-600">
        {{ __('Your password must be changed before you can continue.') }}
    </div>

    <form wire:submit="changePassword">
        <div>
            <x-input-label for="password" :value="__('New Password')" />
            <x-text-input wire:model="password" id="password" class="block mt-1 w-full" type="password" name="password" required autofocus autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('Confirm New Password')" />
            <x-text-input wire:model="password_confirmation" id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-4">
            <button wire:click="logout" type="button" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                {{ __('Log out instead') }}
            </button>

            <x-primary-button>
                {{ __('Change Password') }}
            </x-primary-button>
        </div>
    </form>
</div>
