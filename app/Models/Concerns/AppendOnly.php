<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;

/**
 * Guards a model against `update` and `delete` entirely — every write to it
 * must be a fresh `create()` (CLAUDE.md rule 8). Applied to `AuditLog` and
 * `SecurityEvent`, both append-only by design: a business or security event
 * trail that could be edited or removed after the fact is not a trail.
 *
 * This is Approach A (application-layer) from architecture §15. It stops
 * every path this codebase controls — Eloquent's own `save()`/`delete()`,
 * and therefore anything built on them (mass updates, cascades, a future
 * screen that forgets this rule exists). It does **not** stop a superuser
 * issuing raw SQL against the database directly; closing that gap is
 * Approach B (`REVOKE UPDATE, DELETE` plus a trigger), deferred to the
 * Phase 13 security review.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(function (): void {
            throw new ImmutableRecordException(
                static::class.' rows are append-only and cannot be updated.'
            );
        });

        static::deleting(function (): void {
            throw new ImmutableRecordException(
                static::class.' rows are append-only and cannot be deleted.'
            );
        });
    }
}
