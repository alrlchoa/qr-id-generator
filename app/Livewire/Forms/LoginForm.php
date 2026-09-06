<?php

namespace App\Livewire\Forms;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    #[Validate('required|string')]
    public string $username = '';

    #[Validate('required|string')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    /**
     * Attempt to authenticate the request's credentials.
     *
     * No rate limiting or lockout here by design (architecture §11): a
     * failed attempt is recorded and, after 3 consecutive failures, the
     * user is told to contact a Superadmin — the account itself is never
     * blocked.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $credentials = [
            'username' => $this->username,
            'password' => $this->password,
            'is_active' => true,
        ];

        if (Auth::attempt($credentials, $this->remember)) {
            $user = Auth::user();
            $user->forceFill(['last_login_at' => now()])->save();

            return;
        }

        $user = User::where('username', $this->username)->first();

        SecurityEvent::create([
            'occurred_at' => now(),
            'user_id' => $user?->id,
            'event_type' => 'login_failed',
            'detail' => ['username' => $this->username],
            'ip_address' => request()->ip(),
        ]);

        $message = __('Username/password credentials do not match.');

        if ($user && $this->consecutiveFailureCount($user) >= 3) {
            $message = __('Too many failed attempts. Please contact a Superadmin.');
        }

        // Deliberately not reset() here: a failed attempt keeps what was
        // typed so the user corrects one field rather than retyping both.
        // The password stays in component state only — it is never rendered
        // back into the HTML (see the login view).
        throw ValidationException::withMessages([
            'form.username' => $message,
        ]);
    }

    /**
     * Failed logins since this user's last successful login (or account
     * creation, if they have never logged in).
     */
    private function consecutiveFailureCount(User $user): int
    {
        return SecurityEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'login_failed')
            ->where('occurred_at', '>=', $user->last_login_at ?? $user->created_at)
            ->count();
    }
}
