<?php

namespace App\Exceptions;

use App\Models\Unit;
use RuntimeException;

/**
 * §5.2's six-slot cap, surfaced as a usable error rather than a 500
 * (Phase 8's own "done when," reused here for the promotion/transfer
 * capacity re-attribution checks Phase 7 needs ahead of issuance).
 */
class UnitAtCapacityException extends RuntimeException
{
    public function __construct(public readonly Unit $unit, string $message = 'This unit already has six occupant cards. Revoke one before continuing.')
    {
        parent::__construct($message);
    }
}
