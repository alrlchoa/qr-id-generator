<?php

use App\Enums\Role;
use App\Exceptions\SuperadminInvariantException;
use App\Models\User;
use App\Services\UserAccountManager;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $username = '';

    public string $name = '';

    public string $role = '';

    public ?string $generatedPassword = null;

    public ?string $generatedForUsername = null;

    /** 'created' | 'reset' — which banner wording applies to the password above. */
    public ?string $generatedPasswordContext = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);

        $this->role = Role::Reader->value;
    }

    public function with(): array
    {
        return [
            'users' => User::orderBy('username')->get(),
            'roles' => Role::cases(),
        ];
    }

    public function createAccount(UserAccountManager $accounts): void
    {
        $this->authorize('create', User::class);

        $validated = $this->validate([
            'username' => ['required', 'string', 'max:255', 'unique:users,username'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        $result = $accounts->createAccount(
            auth()->user(),
            $validated['username'],
            $validated['name'],
            Role::from($validated['role']),
        );

        $this->generatedPassword = $result['password'];
        $this->generatedForUsername = $result['user']->username;
        $this->generatedPasswordContext = 'created';

        $this->reset('username', 'name');
        $this->role = Role::Reader->value;
    }

    public function toggleActive(int $userId, UserAccountManager $accounts): void
    {
        $target = User::findOrFail($userId);

        $this->authorize('update', $target);

        // No validate() call happens in this method, so Livewire never
        // auto-clears a previous addError('invariant', ...) — that only
        // happens on a successful validate() for the same key. Without this,
        // a failed toggle for one user would keep showing after a later,
        // unrelated user's toggle succeeds.
        $this->resetErrorBag('invariant');

        try {
            if ($target->is_active) {
                $accounts->disable(auth()->user(), $target);
            } else {
                $accounts->enable(auth()->user(), $target);
            }
        } catch (SuperadminInvariantException $e) {
            $this->addError('invariant', $e->getMessage());
        }
    }

    public function changeRole(int $userId, string $role, UserAccountManager $accounts): void
    {
        $target = User::findOrFail($userId);

        $this->authorize('update', $target);

        // See toggleActive()'s own note — no validate() call happens here
        // either, so a stale error would otherwise never clear on its own.
        $this->resetErrorBag('invariant');

        try {
            $accounts->changeRole(auth()->user(), $target, Role::from($role));
        } catch (SuperadminInvariantException $e) {
            $this->addError('invariant', $e->getMessage());
        }
    }

    /**
     * The only path a password changes other than the mandatory rotation it
     * forces (CLAUDE.md) — self-service, current-password-known changes were
     * deliberately removed rather than kept alongside this one.
     */
    public function resetPassword(int $userId, UserAccountManager $accounts): void
    {
        $target = User::findOrFail($userId);

        $this->authorize('update', $target);

        $result = $accounts->resetPassword(auth()->user(), $target);

        $this->generatedPassword = $result['password'];
        $this->generatedForUsername = $result['user']->username;
        $this->generatedPasswordContext = 'reset';
    }
}; ?>

<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Manage Users') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @error('invariant')
                <div class="p-4 bg-red-100 text-red-700 rounded-lg">{{ $message }}</div>
            @enderror

            @if ($generatedPassword)
                <div class="p-4 bg-yellow-100 text-yellow-800 rounded-lg">
                    @if ($generatedPasswordContext === 'reset')
                        {{ __('Password reset for :username. One-time password (shown once — write it down now):', ['username' => $generatedForUsername]) }}
                    @else
                        {{ __('Account created for :username. One-time password (shown once — write it down now):', ['username' => $generatedForUsername]) }}
                    @endif
                    <span class="font-mono font-bold">{{ $generatedPassword }}</span>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium mb-4">{{ __('Create Account') }}</h3>
                <form wire:submit="createAccount" class="space-y-4 max-w-xl">
                    <div>
                        <x-input-label for="username" :value="__('Username')" />
                        <x-text-input wire:model="username" id="username" class="block mt-1 w-full" type="text" required autofocus />
                        <x-input-error :messages="$errors->get('username')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="name" :value="__('Full Name')" />
                        <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" required />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="role" :value="__('Role')" />
                        <select wire:model="role" id="role" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
                            @foreach ($roles as $roleOption)
                                <option value="{{ $roleOption->value }}">{{ $roleOption->label() }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('role')" class="mt-2" />
                    </div>

                    <x-primary-button>{{ __('Create Account') }}</x-primary-button>
                </form>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg overflow-x-auto">
                <h3 class="text-lg font-medium mb-4">{{ __('Accounts') }}</h3>
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="py-2 pr-4">{{ __('Username') }}</th>
                            <th class="py-2 pr-4">{{ __('Name') }}</th>
                            <th class="py-2 pr-4">{{ __('Role') }}</th>
                            <th class="py-2 pr-4">{{ __('Active') }}</th>
                            <th class="py-2 pr-4">{{ __('Must change password') }}</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $user)
                            <tr class="border-b" wire:key="user-{{ $user->id }}">
                                <td class="py-2 pr-4">{{ $user->username }}</td>
                                <td class="py-2 pr-4">{{ $user->name }}</td>
                                <td class="py-2 pr-4">
                                    @if ($user->id === auth()->id())
                                        {{ $user->role->label() }}
                                    @else
                                        <select wire:change="changeRole({{ $user->id }}, $event.target.value)" class="border-gray-300 rounded-md text-sm">
                                            @foreach ($roles as $roleOption)
                                                <option value="{{ $roleOption->value }}" @selected($user->role === $roleOption)>{{ $roleOption->label() }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $user->is_active ? __('Yes') : __('No') }}</td>
                                <td class="py-2 pr-4">{{ $user->must_change_password ? __('Yes') : __('No') }}</td>
                                <td class="py-2 space-x-3">
                                    <button wire:click="resetPassword({{ $user->id }})"
                                            wire:confirm="{{ __('Reset the password for :username? A new one-time password will be generated and this cannot be undone.', ['username' => $user->username]) }}"
                                            type="button" class="underline text-sm text-gray-600 hover:text-gray-900">
                                        {{ __('Reset Password') }}
                                    </button>
                                    @if ($user->id !== auth()->id())
                                        <button wire:click="toggleActive({{ $user->id }})" type="button" class="underline text-sm text-gray-600 hover:text-gray-900">
                                            {{ $user->is_active ? __('Disable') : __('Enable') }}
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
