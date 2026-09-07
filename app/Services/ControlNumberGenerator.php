<?php

namespace App\Services;

use App\Models\IdCard;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Mints `id_cards.control_number` — char(8), zero-padded, random, the value
 * the QR encodes in plaintext (architecture §8, CLAUDE.md rule 15). Same
 * shape as `PersonIdNumberGenerator`: collision retry is typed (rule 16),
 * matched by constraint name, never by message text.
 */
class ControlNumberGenerator
{
    private const UNIQUE_CONSTRAINT = 'uq_id_cards_control_number';

    private const MAX_ATTEMPTS = 5;

    public function generate(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

            if (! IdCard::where('control_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate a unique control_number after '.self::MAX_ATTEMPTS.' attempts.');
    }

    /**
     * Create an IdCard, retrying generation if a race lost the uniqueness
     * check above to a concurrent insert.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createWithUniqueControlNumber(array $attributes): IdCard
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return IdCard::create(['control_number' => $this->generate(), ...$attributes]);
            } catch (UniqueConstraintViolationException $e) {
                if ($e->index !== self::UNIQUE_CONSTRAINT) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to create an IdCard with a unique control_number after '.self::MAX_ATTEMPTS.' attempts.');
    }
}
