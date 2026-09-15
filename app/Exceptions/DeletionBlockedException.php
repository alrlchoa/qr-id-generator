<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Soft deletes are guarded, never cascading (CLAUDE.md rule 9). Thrown when
 * a person or unit has a live dependent — an active relationship or card —
 * that the admin has to close first. `$detail` carries whatever the caller
 * needs to name in the error and, per architecture §13, in the
 * `deletion_blocked` security event.
 */
class DeletionBlockedException extends RuntimeException
{
    /**
     * Free-form by design — it lands in `security_events.detail`, a jsonb
     * column, and each caller names what its own refusal needs (a person's
     * blocking relationship/card ids; a unit's code plus the same). Typed
     * as `array<int, string>` until Phase 14, which was simply wrong and
     * had never been checked: the annotation sat on the class docblock,
     * where PHPStan doesn't read it, rather than on the constructor.
     *
     * @param  array<string, mixed>  $detail
     */
    public function __construct(string $message, public readonly array $detail = [])
    {
        parent::__construct($message);
    }
}
