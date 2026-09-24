<?php

namespace App\Services;

use App\Models\IdCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Cards list's "Export unprinted cards" action (Phase 19 plan). Every
 * active card with no `printed_at` yet, all three types together, as one
 * Smart IDesigner import zip — and exporting marks every card in it
 * printed, the same one-way fact `CardPrintService::print()` sets for a
 * single card (rule 59).
 */
class BulkCardExportService
{
    public function __construct(
        private readonly SmartIdesignerZip $zipBuilder,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array{path: string, filename: string, count: int}
     *
     * @throws InvalidArgumentException if there is nothing to export, or if
     *                                  any card's photo is missing — either
     *                                  way, nothing is marked printed
     */
    public function export(User $actor): array
    {
        return DB::transaction(function () use ($actor) {
            // Locked for the duration of the transaction: two admins
            // exporting at once serialize, and the second sees only
            // whatever the first didn't already claim.
            $cards = IdCard::query()
                ->where('status', 'active')
                ->whereNull('printed_at')
                ->with(['person', 'unit'])
                ->lockForUpdate()
                ->get();

            if ($cards->isEmpty()) {
                throw new InvalidArgumentException('There are no unprinted active cards to export.');
            }

            $path = tempnam(sys_get_temp_dir(), 'bulk-card-export').'.zip';

            // Photo-missing and every other build failure throws before
            // anything below runs — nothing is marked printed on a failed
            // build.
            $this->zipBuilder->build($cards, $path);

            $printedAt = now();

            IdCard::whereIn('id', $cards->pluck('id'))->update(['printed_at' => $printedAt]);

            foreach ($cards as $card) {
                $this->auditLogger->log(
                    actor: $actor,
                    action: 'id_printed',
                    subject: $card,
                    newValue: ['printed_at' => $printedAt->toISOString(), 'via' => 'bulk_export'],
                );
            }

            return [
                'path' => $path,
                'filename' => 'id-cards-'.$printedAt->format('Y-m-d-Hi').'.zip',
                'count' => $cards->count(),
            ];
        });
    }
}
