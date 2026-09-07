<?php

namespace App\Services;

use App\Models\Person;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Mints `people.user_id_number` — char(8), zero-padded, random, permanent,
 * for every party regardless of `entity_type` (architecture §6, CLAUDE.md
 * rule 14, rule 35). Companies get one too: never printed, but every row
 * gets exactly one stable identifier to search or quote.
 *
 * Collision retry is typed (CLAUDE.md rule 16): only a
 * `UniqueConstraintViolationException` whose index matches the
 * `user_id_number` unique constraint by name triggers a retry. Anything
 * else — a validation failure, an unrelated constraint — propagates.
 */
class PersonIdNumberGenerator
{
    private const UNIQUE_CONSTRAINT = 'uq_people_user_id_number';

    private const MAX_ATTEMPTS = 10;

    public function generate(): string
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $candidate = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

            if (! Person::withTrashed()->where('user_id_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate a unique user_id_number after '.self::MAX_ATTEMPTS.' attempts.');
    }

    /**
     * Create a Person, retrying generation if a race lost the uniqueness
     * check above to a concurrent insert.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createWithUniqueId(array $attributes): Person
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return Person::create(['user_id_number' => $this->generate(), ...$attributes]);
            } catch (UniqueConstraintViolationException $e) {
                if ($e->index !== self::UNIQUE_CONSTRAINT) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Unable to create a Person with a unique user_id_number after '.self::MAX_ATTEMPTS.' attempts.');
    }
}
