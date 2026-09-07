<?php

namespace App\Support;

/**
 * Architecture §9.2's "server-side session record": a map of person ID to
 * the timestamp a Reader last verified them, written by
 * `CardVerificationService::verify()` and consulted — never re-derived from
 * the request — by `PersonPolicy::viewPhoto()`. No schema, per architecture:
 * "No schema is needed at this scale; naming it here prevents it being
 * reinvented as a table."
 *
 * This is a short-lived *grant*, not a staleness check — CLAUDE.md rule 3
 * ("exactly one place compares a date to today") is about state derived
 * from a date, which this isn't: nothing about a person's own record
 * changes here, only what a Reader's own session is currently allowed to
 * fetch.
 */
class ReaderVerificationSession
{
    private const SESSION_KEY = 'reader_verified_persons';

    private const WINDOW_SECONDS = 60;

    public function record(int $personId): void
    {
        $verified = session(self::SESSION_KEY, []);
        $verified[$personId] = now()->timestamp;
        session([self::SESSION_KEY => $verified]);
    }

    public function isRecentlyVerified(int $personId): bool
    {
        $verifiedAt = session(self::SESSION_KEY, [])[$personId] ?? null;

        return $verifiedAt !== null && (now()->timestamp - $verifiedAt) <= self::WINDOW_SECONDS;
    }
}
