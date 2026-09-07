<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Issuance refused for a reason that isn't capacity (`UnitAtCapacityException`
 * covers that one separately). Two distinct causes share this shape (Phase 8
 * plan): a company refused by kind, checked before any field check, and a
 * natural person below the cardable tier, which names the missing fields.
 * `$missingFields` is empty for the by-kind refusal.
 *
 * @param  array<int, string>  $missingFields
 */
class CardIssuanceRefusedException extends RuntimeException
{
    public function __construct(string $message, public readonly array $missingFields = [])
    {
        parent::__construct($message);
    }
}
