<?php

namespace App\Services;

use App\Models\IdCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ZipArchive;

/**
 * The one builder for the Smart IDesigner import shape (Phase 19 plan),
 * shared by `BulkCardExportService` (many cards) and `CardPrintService`
 * (one card) so the two can never produce different shapes. Flat — no
 * folders: one CSV per type present in the batch — `unitOwner.csv`,
 * `tenant.csv`, `employee.csv` (`Photo,Name,Unit,Code`) — plus every
 * card's photo, all at the zip's root, named `<control_number>.jpg`.
 * Control numbers are globally unique, so nothing needs a folder to
 * avoid colliding. **A type with no cards in the batch gets no file at
 * all** — never a header-only spreadsheet — since Smart IDesigner is only
 * ever pointed at the files that exist.
 *
 * Plain CSV, not a spreadsheet library — Smart IDesigner imports CSV
 * directly, and a CSV cell carries no numeric/text type the way an XLSX
 * cell does, so `Code`'s leading zeros survive as the literal characters
 * they are with no special handling (unlike the XLSX-era `StringCell`
 * trick this replaced — see this phase's own trap note). Lines are built
 * by hand (`csvLine()`), not `fputcsv()`: that function also quotes a
 * field for merely containing a space, which would wrap every ordinary
 * "First Last" name in quotes for no reason — `csvLine()` quotes only a
 * field that actually needs it.
 */
class SmartIdesignerZip
{
    private const FILENAMES = [
        'owner' => 'unitOwner.csv',
        'tenant' => 'tenant.csv',
        'employee' => 'employee.csv',
    ];

    /**
     * @param  Collection<int, IdCard>  $cards  person and unit already eager-loaded
     *
     * @throws InvalidArgumentException if any card's photo is missing from disk —
     *                                  nothing is written; a missing photo is a
     *                                  disk/backup fault to fix, not a card to skip
     */
    public function build(Collection $cards, string $zipPath): void
    {
        $this->assertPhotosExist($cards);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach (self::FILENAMES as $type => $filename) {
            $cardsOfType = $this->sorted($cards->where('type', $type), $type);

            if ($cardsOfType->isEmpty()) {
                continue;
            }

            $zip->addFromString($filename, $this->buildCsv($cardsOfType));

            foreach ($cardsOfType as $card) {
                $zip->addFile(
                    Storage::disk('local')->path($card->person->photo_path),
                    "{$card->control_number}.jpg",
                );
            }
        }

        $zip->close();
    }

    /**
     * @param  Collection<int, IdCard>  $cards
     */
    private function assertPhotosExist(Collection $cards): void
    {
        $missing = $cards
            ->filter(fn (IdCard $card) => $card->person->photo_path === null
                || ! Storage::disk('local')->exists($card->person->photo_path))
            ->pluck('control_number');

        if ($missing->isNotEmpty()) {
            throw new InvalidArgumentException(
                'Missing photo file(s) for control number(s): '.$missing->implode(', ')
            );
        }
    }

    /**
     * @param  Collection<int, IdCard>  $cards
     * @return Collection<int, IdCard>
     */
    private function sorted(Collection $cards, string $type): Collection
    {
        if ($type === 'employee') {
            return $cards->sortBy(fn (IdCard $card) => $card->person->exportName())->values();
        }

        return $cards
            ->sortBy(fn (IdCard $card) => sprintf('%s|%s', $card->unit?->unitCode() ?? '', $card->person->exportName()))
            ->values();
    }

    /**
     * @param  Collection<int, IdCard>  $cards
     */
    private function buildCsv(Collection $cards): string
    {
        $lines = [$this->csvLine(['Photo', 'Name', 'Unit', 'Code'])];

        foreach ($cards as $card) {
            $lines[] = $this->csvLine([
                "{$card->control_number}.jpg",
                $card->person->exportName(),
                $card->unit?->unitCode() ?? '',
                $card->control_number,
            ]);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * A minimal RFC 4180 line, deliberately not `fputcsv()`: that function
     * also quotes a field for merely containing a space — every ordinary
     * "First Last" name — which is unwanted noise here and, per the user
     * who asked for it removed, visible in the finished ID. A field is
     * quoted only when it actually needs to be: a comma, a double quote,
     * or a newline would otherwise be indistinguishable from a delimiter.
     *
     * @param  array<int, string>  $fields
     */
    private function csvLine(array $fields): string
    {
        return implode(',', array_map(function (string $field): string {
            if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n") || str_contains($field, "\r")) {
                return '"'.str_replace('"', '""', $field).'"';
            }

            return $field;
        }, $fields));
    }
}
