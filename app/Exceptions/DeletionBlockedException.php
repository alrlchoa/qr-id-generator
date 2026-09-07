<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Soft deletes are guarded, never cascading (CLAUDE.md rule 9). Thrown when
 * a person or unit has a live dependent — an active relationship or card —
 * that the admin has to close first. `$detail` carries whatever the caller
 * needs to name in the error and, per architecture §13, in the
 * `deletion_blocked` security event.
 *
 * @param  array<int, string>  $detail
 */
class DeletionBlockedException extends RuntimeException
{
    public function __construct(string $message, public readonly array $detail = [])
    {
        parent::__construct($message);
    }
}
