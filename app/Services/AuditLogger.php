<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The one call-site shape for `audit_logs` (Phase 4 plan, architecture §3).
 * Every business event in the system — GUI, console, or the first-run
 * wizard — writes through here, never through a raw `AuditLog::create()`.
 * One shape means one place to check when auditing "does this event get
 * logged correctly," instead of as many shapes as there are callers.
 *
 * `actor` is the authenticated user responsible, or `null` for a console
 * command or the setup wizard — neither has one (architecture §12). When
 * `actor` is null, `actingAs` is required: it is the only source of
 * `user_role` left, and there is no sane default to fall back to silently.
 *
 * `ipAddress` and `occurredAt` are read from the current request/clock
 * automatically rather than accepted as parameters. A console command has
 * no request to read an IP from — `app()->runningInConsole()` is what
 * decides that, not a caller remembering to pass null.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $previousValue
     * @param  array<string, mixed>|null  $newValue
     */
    public function log(
        ?User $actor,
        string $action,
        Model $subject,
        ?array $previousValue = null,
        ?array $newValue = null,
        ?string $actingAs = null,
    ): AuditLog {
        $userRole = $actor?->role->value ?? $actingAs;

        if ($userRole === null) {
            throw new \InvalidArgumentException(
                'AuditLogger::log() needs either an authenticated $actor or an explicit $actingAs — there is no default for a null-actor event.'
            );
        }

        return AuditLog::create([
            'occurred_at' => now(),
            'user_id' => $actor?->id,
            'user_role' => $userRole,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'previous_value' => $previousValue,
            'new_value' => $newValue,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    /**
     * `{"os_user", "hostname"}`, merged into a console command's `new_value`
     * (Phase 4 plan) so the trail records which shell session did it — the
     * same reasoning as architecture §12's console break-glass commands.
     *
     * @return array{os_user: string, hostname: string|false}
     */
    public static function consoleProvenance(): array
    {
        return [
            'os_user' => get_current_user(),
            'hostname' => gethostname(),
        ];
    }
}
