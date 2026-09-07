<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Every unit has exactly one primary owner, always (architecture §3, §5.4).
 * Thrown whenever an operation would leave that untrue — refusing to open a
 * unit without one, promoting/transferring into an invalid state, or a
 * person's own deletion being blocked while they're a primary owner
 * somewhere (§13).
 */
class PrimaryOwnerInvariantException extends RuntimeException
{
    //
}
