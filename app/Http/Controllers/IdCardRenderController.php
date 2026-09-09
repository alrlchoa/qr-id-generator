<?php

namespace App\Http\Controllers;

use App\Models\IdCard;
use App\Models\SecurityEvent;
use App\Services\CardRenderer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Serves a card's composited front or back as a PNG, rendered fresh on
 * every request — nothing is cached to disk, since a card's own data
 * (photo, name) can change and there is no historical-reprint feature to
 * protect (rule 13). Gated by `IdCardPolicy::manageLifecycle()`, narrower
 * than the ordinary `view` ability: the front embeds the person's photo
 * with no 60-second window (rule 23 is `PersonPhotoController`'s alone),
 * so a Reader — who legitimately reaches `view` for the verify flow — must
 * never reach this route.
 */
class IdCardRenderController extends Controller
{
    public function front(IdCard $idCard, CardRenderer $renderer): Response
    {
        $this->authorizeRendering($idCard);

        try {
            $bytes = $renderer->renderFront($idCard);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        return response($bytes)->header('Content-Type', 'image/png');
    }

    public function back(IdCard $idCard, CardRenderer $renderer): Response
    {
        $this->authorizeRendering($idCard);

        try {
            $bytes = $renderer->renderBack($idCard);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        return response($bytes)->header('Content-Type', 'image/png');
    }

    private function authorizeRendering(IdCard $idCard): void
    {
        if (Gate::denies('manageLifecycle', IdCard::class)) {
            SecurityEvent::create([
                'occurred_at' => now(),
                'user_id' => auth()->id(),
                'event_type' => 'authorization_denied',
                'detail' => ['id_card_id' => $idCard->id, 'route' => 'id-cards.render'],
                'ip_address' => request()->ip(),
            ]);

            abort(403);
        }
    }
}
