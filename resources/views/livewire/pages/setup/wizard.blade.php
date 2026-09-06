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
            'first_password' => ['required', 'string', 'min:8'],
            'first_password_confirmation' => ['required', 'same:first_password'],
            'second_username' => ['required', 'string', 'max:255', 'different:first_username', Rule::unique('users', 'username')],
            'second_name' => ['required', 'string', 'max:255'],
            'second_password' => ['required', 'string', 'min:8'],
            'second_password_confirmation' => ['required', 'same:second_password'],
        ], [
            'second_username.different' => __('The two accounts must have different usernames.'),
            'first_password.min' => __('Password is less than 8 characters long.'),
            'second_password.min' => __('Password is less than 8 characters long.'),
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

    {{--
        Three checks run entirely in the browser: duplicate usernames, short
        passwords, and mismatched confirmations. They are the mistakes a
        person makes while typing, and none needs the server to detect.

        They advise; they never block. An earlier version disabled the submit
        button while any of them held, which produced a form that silently did
        nothing when clicked — indistinguishable from a broken app. The server
        rules decide, and they can only decide if the form reaches them.

        Kept deliberately plain. Anything elaborate here — getters in x-data,
        computed state — is JavaScript standing between an operator and the
        only page that can bootstrap this system, and a syntax error in it
        takes wire:submit down with it, silently. Six strings and five
        comparisons is all this needs.

        Note what is NOT here: `value="..."` on the wire:model inputs. Livewire
        owns those values; setting the attribute as well fights it, and an
        input reset to empty while the typed value lives only in Livewire's
        state makes the browser's own `required` check block submission — no
        request, no error, nothing in the log.

        This deliberately does not use wire:model.blur. Every field here is a
        deferred wire:model, so any mid-typing round trip re-renders them all
        from server state that has not been sent yet, wiping what was typed.
        That was a real regression, and it is why the checks are Alpine-local.

        Client-side validation is convenience, never enforcement: the rules in
        bootstrapSystem() still decide, and they are what a request bypassing
        this page hits.
    --}}
    {{--
        A summary of everything wrong, above the form. Per-field messages are
        still the primary signal, but a long form scrolls: an error under the
        second password can sit below the fold while the submit button the
        operator just pressed is in view, which reads as "nothing happened"
        rather than "something is wrong". This is the only page that can
        bootstrap the system, so a silent refusal here is the worst outcome
        available.
    --}}
    @if ($errors->any())
        <div class="mb-6 rounded-md border border-red-300 bg-red-50 p-4">
            <p class="text-sm font-medium text-red-800">
                {{ __('The accounts were not created:') }}
            </p>
            <ul class="mt-2 list-disc list-inside text-sm text-red-700">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="bootstrapSystem"
          x-data="{ u1: '', u2: '', p1: '', c1: '', p2: '', c2: '' }">
        <fieldset class="border-t border-gray-200 pt-4">
            <legend class="text-sm font-medium text-gray-900">{{ __('First Superadmin') }}</legend>

            <div class="mt-4">
                <x-input-label for="first_username" :value="__('Username')" />
                <x-text-input wire:model="first_username" x-on:input="u1 = $event.target.value" id="first_username" class="block mt-1 w-full" type="text" required autofocus autocomplete="off" />
                <x-input-error :messages="$errors->get('first_username')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="first_name" :value="__('Display name')" />
                <x-text-input wire:model="first_name" id="first_name" class="block mt-1 w-full" type="text" required autocomplete="off" />
                <x-input-error :messages="$errors->get('first_name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="first_password" :value="__('Password')" />
                <x-text-input wire:model="first_password" x-on:input="p1 = $event.target.value" id="first_password" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                <p x-show="p1 !== '' && p1.length < 8" style="display: none" class="mt-2 text-sm text-red-600">
                    {{ __('Password is less than 8 characters long.') }}
                </p>
                <x-input-error :messages="$errors->get('first_password')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="first_password_confirmation" :value="__('Confirm password')" />
                <x-text-input wire:model="first_password_confirmation" x-on:input="c1 = $event.target.value" id="first_password_confirmation" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                <p x-show="c1 !== '' && c1 !== p1" style="display: none" class="mt-2 text-sm text-red-600">
                    {{ __('Confirm Password is not the same.') }}
                </p>
                <x-input-error :messages="$errors->get('first_password_confirmation')" class="mt-2" />
            </div>
        </fieldset>

        <fieldset class="border-t border-gray-200 pt-4 mt-8">
            <legend class="text-sm font-medium text-gray-900">{{ __('Second Superadmin') }}</legend>

            <div class="mt-4">
                <x-input-label for="second_username" :value="__('Username')" />
                <x-text-input wire:model="second_username" x-on:input="u2 = $event.target.value" id="second_username" class="block mt-1 w-full" type="text" required autocomplete="off" />
                <p x-show="u2 !== '' && u1 === u2" style="display: none" class="mt-2 text-sm text-red-600">
                    {{ __('The two accounts must have different usernames.') }}
                </p>
                <x-input-error :messages="$errors->get('second_username')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="second_name" :value="__('Display name')" />
                <x-text-input wire:model="second_name" id="second_name" class="block mt-1 w-full" type="text" required autocomplete="off" />
                <x-input-error :messages="$errors->get('second_name')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="second_password" :value="__('Password')" />
                <x-text-input wire:model="second_password" x-on:input="p2 = $event.target.value" id="second_password" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                <p x-show="p2 !== '' && p2.length < 8" style="display: none" class="mt-2 text-sm text-red-600">
                    {{ __('Password is less than 8 characters long.') }}
                </p>
                <x-input-error :messages="$errors->get('second_password')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="second_password_confirmation" :value="__('Confirm password')" />
                <x-text-input wire:model="second_password_confirmation" x-on:input="c2 = $event.target.value" id="second_password_confirmation" class="block mt-1 w-full" type="password" required autocomplete="new-password" />
                <p x-show="c2 !== '' && c2 !== p2" style="display: none" class="mt-2 text-sm text-red-600">
                    {{ __('Confirm Password is not the same.') }}
                </p>
                <x-input-error :messages="$errors->get('second_password_confirmation')" class="mt-2" />
            </div>
        </fieldset>

        <div class="flex items-center justify-end gap-4 mt-6">
            <x-primary-button>
                {{ __('Create both accounts') }}
            </x-primary-button>
        </div>
    </form>
</div>
