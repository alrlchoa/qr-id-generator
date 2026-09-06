<?php

use App\Exceptions\SuperadminInvariantException;
use App\Services\SystemBootstrap;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $first_username = '';

    public string $first_name = '';

    public string $first_password = '';

    public string $first_password_confirmation = '';

    public string $second_username = '';

    public string $second_name = '';

    public string $second_password = '';

    public string $second_password_confirmation = '';

    /**
     * Live feedback for the one mistake that is invisible until submit:
     * two accounts sharing a username. Everything else is obvious as you
     * type; this is only apparent by comparing two fields several rows
     * apart, so it is checked as soon as either one is filled in.
     */
    public function updated(string $property): void
    {
        if (! in_array($property, ['first_username', 'second_username'], true)) {
            return;
        }

        $this->resetErrorBag('second_username');

        if ($this->second_username !== '' && $this->first_username === $this->second_username) {
            $this->addError('second_username', __('The two accounts must have different usernames.'));
        }
    }

    /**
     * Both accounts are created together or not at all (architecture §12).
     * Stopping after one would leave the one-member tier the §11 invariant
     * exists to prevent.
     */
    public function bootstrapSystem(SystemBootstrap $bootstrap): void
    {
        // `same:` rather than `confirmed:` on purpose — `confirmed` reports
        // against the password field, which puts "confirm doesn't match"
        // under the wrong input. This lands each message under the field
        // the user has to fix.
        $validated = $this->validate([
            'first_username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'first_name' => ['required', 'string', 'max:255'],
            'first_password' => ['required', 'string', 'min:12'],
            'first_password_confirmation' => ['required', 'same:first_password'],
            'second_username' => ['required', 'string', 'max:255', 'different:first_username', Rule::unique('users', 'username')],
            'second_name' => ['required', 'string', 'max:255'],
            'second_password' => ['required', 'string', 'min:12'],
            'second_password_confirmation' => ['required', 'same:second_password'],
        ], [
            'second_username.different' => __('The two accounts must have different usernames.'),
            'first_password.min' => __('Password is less than 12 characters long.'),
            'second_password.min' => __('Password is less than 12 characters long.'),
            'first_password_confirmation.same' => __('Confirm Password is not the same.'),
            'second_password_confirmation.same' => __('Confirm Password is not the same.'),
            'first_password_confirmation.required' => __('Confirm Password is not the same.'),
            'second_password_confirmation.required' => __('Confirm Password is not the same.'),
        ]);

        try {
            $bootstrap->bootstrap(
                [
                    'username' => $validated['first_username'],
                    'name' => $validated['first_name'],
                    'password' => $validated['first_password'],
                ],
                [
                    'username' => $validated['second_username'],
                    'name' => $validated['second_name'],
                    'password' => $validated['second_password'],
                ],
            );
        } catch (SuperadminInvariantException $e) {
            // Someone else completed the wizard between this page loading
            // and this submit. Surfaced on the form rather than as a 500,
            // and everything typed stays put.
            $this->addError('first_username', $e->getMessage());

            return;
        }

        Session::flash('status', __('Both Superadmin accounts were created. Sign in to continue.'));

        $this->redirect(route('login', absolute: false), navigate: true);
    }
}; ?>

<div>
    <div class="mb-6">
        <h1 class="text-lg font-medium text-gray-900">
            {{ __('Set up this system') }}
        </h1>

        <p class="mt-2 text-sm text-gray-600">
            {{ __('Create the two Superadmin accounts this system requires. Both are created together, and this page will not be reachable again afterward.') }}
        </p>

        <p class="mt-2 text-sm text-gray-600">
            {{ __('Give the two accounts to two different people. Neither password can be recovered by email — there is no mail server.') }}
        </p>
    </div>

    <form wire:submit="bootstrapSystem">
        <fieldset class="border-t border-gray-200 pt-4">
            <legend class="text-sm font-medium text-gray-900">{{ __('First Superadmin') }}</legend>

            <div class="mt-4">
                <x-input-label for="first_username" :value="__('Username')" />
                <x-text-input wire:model.blur="first_username" id="first_username" class="block mt-1 w-full" type="text" required autofocus autocomplete="off" />
                <x-input-error :messages="$errors->get('first_username')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="first_name" :value="__('Display name')" />
                <x-text-input wire:model="first_name" id="first_name" class="block mt-1 w-full" type="text" required autocomplete="off" />
                <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="first_password" :value="__('Password')" />
                <x-text-input wire:model="first_password" id="first_password" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('first_password')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="first_password_confirmation" :value="__('Confirm password')" />
                <x-text-input wire:model="first_password_confirmation" id="first_password_confirmation" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('first_password_confirmation')" class="mt-2" />
            </div>
        </fieldset>

        <fieldset class="border-t border-gray-200 pt-4 mt-8">
            <legend class="text-sm font-medium text-gray-900">{{ __('Second Superadmin') }}</legend>

            <div class="mt-4">
                <x-input-label for="second_username" :value="__('Username')" />
                <x-text-input wire:model.blur="second_username" id="second_username" class="block mt-1 w-full" type="text" required autocomplete="off" />
                <x-input-error :messages="$errors->get('second_username')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="second_name" :value="__('Display name')" />
                <x-text-input wire:model="second_name" id="second_name" class="block mt-1 w-full" type="text" required autocomplete="off" />
                <x-input-error :messages="$errors->get('second_name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="second_password" :value="__('Password')" />
                <x-text-input wire:model="second_password" id="second_password" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('second_password')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="second_password_confirmation" :value="__('Confirm password')" />
                <x-text-input wire:model="second_password_confirmation" id="second_password_confirmation" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('second_password_confirmation')" class="mt-2" />
            </div>
        </fieldset>

        <div class="flex items-center justify-end mt-6">
            <x-primary-button>
                {{ __('Create both accounts') }}
            </x-primary-button>
        </div>
    </form>
</div>
