<?php

namespace App\Services;

use App\Models\IdCard;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\ReaderVerificationSession;

/**
 * The guardhouse flow (architecture §8): a control number in, current
 * status and identifying info out, no decryption step. A card that isn't
 * `active` still returns its true status — a readable QR is never evidence
 * of validity.
 */
class CardVerificationService
{
    public function __construct(private readonly ReaderVerificationSession $session) {}

    /**
     * @return array{card: ?IdCard}
     */
    public function verify(?User $actor, string $rawControlNumber): array
    {
        $controlNumber = str_pad(preg_replace('/\D/', '', $rawControlNumber) ?? '', 8, '0', STR_PAD_LEFT);

        $card = IdCard::where('control_number', $controlNumber)->with(['person', 'unit'])->first();

        if ($card === null) {
            SecurityEvent::create([
                'occurred_at' => now(),
                'user_id' => $actor?->id,
                'event_type' => 'qr_verify_miss',
                'detail' => ['attempted_value' => $rawControlNumber],
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            ]);

            return ['card' => null];
        }

        // The verify itself is what opens the 60-second photo window — not
        // a separate "select this person" step. Recorded regardless of the
        // card's status: an expired/revoked card is still a verification
        // of who that person is, and the guard comparing faces against the
        // photo is exactly the check that matters most on a non-active hit.
        $this->session->record($card->person_id);

        return ['card' => $card];
    }
}
