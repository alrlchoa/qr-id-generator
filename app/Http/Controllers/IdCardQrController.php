<?php

namespace App\Http\Controllers;

use App\Models\IdCard;
use App\Services\QrCodeGenerator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 10's "print/preview surface for the QR" — deliberately not part of
 * a card's own show page, since none exists yet (the Card lifecycle screen
 * is deferred to Phase 12 alongside "Issue ID"). A bare, linkable preview
 * is enough to satisfy this phase's own checklist without building the
 * screen that phase owns.
 */
class IdCardQrController extends Controller
{
    public function __invoke(IdCard $idCard, QrCodeGenerator $qr): View
    {
        Gate::authorize('view', $idCard);

        return view('id-cards.qr', [
            'idCard' => $idCard,
            'svg' => $qr->svgFor($idCard->control_number),
        ]);
    }
}
