<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The uploaded overlay PNG is substantially opaque over the QR field's box.
 * Phase 12's alpha check refuses this outright, with no force override — the
 * QR is where "it renders" and "it works" come apart (texture, or anything
 * intruding on the quiet zone, can break scanning while looking fine on
 * screen), and Phase 10 proved scanning against real hardware, not a
 * screen preview. Every other field can be forced past its own warning;
 * this one cannot.
 */
class TemplateOverlayObscuresQrException extends RuntimeException
{
    public function __construct(string $message = 'The uploaded artwork is opaque over the QR code\'s position and would likely block scanning. Move the QR field, or cut a transparent window for it in the artwork.')
    {
        parent::__construct($message);
    }
}
