<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The uploaded overlay PNG is substantially opaque over one or more
 * non-QR field boxes (photo, name, unit number, or role). Unlike
 * `TemplateOverlayObscuresQrException`, this is only a warning — partial
 * coverage here is usually the intended border effect (the whole reason
 * the overlay composites last) — so `TemplateManager::saveFieldPositions()`
 * throws it once, and a caller that passes `force: true` on the retry gets
 * the save through anyway. An opaque overlay with no field visible at all
 * is a silent failure with no natural symptom otherwise, which is the
 * entire reason this check exists.
 *
 * @param  list<string>  $obscuredFields
 */
class TemplateFieldObscuredException extends RuntimeException
{
    public function __construct(public readonly array $obscuredFields, string $message)
    {
        parent::__construct($message);
    }
}
