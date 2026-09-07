<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when code attempts to update or delete a row from an append-only
 * table (`audit_logs`, `security_events` — CLAUDE.md rule 8). This is the
 * application-layer half of that guarantee (Approach A). DB-level grant and
 * trigger enforcement (Approach B) is deferred to the Phase 13 security
 * review per architecture §15 — this exception is what stands in for it
 * until then.
 */
class ImmutableRecordException extends RuntimeException {}
