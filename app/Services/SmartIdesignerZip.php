<?php

namespace App\Services;

use App\Models\IdCard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

/**
 * The one builder for the Smart IDesigner import shape (Phase 19 plan),
 * shared by `BulkCardExportService` (many cards) and `CardPrintService`
 * (one card) so the two can never produce different shapes. Always three
 * folders — `Unit Owner/`, `Tenant/`, `Employee/` — each holding a
 * `cards.xlsx` (`Image | Name | Unit | Code`) and the photo of every card
 * it lists, named `<control_number>.jpg`. A type with no cards still gets
 * a header-only `cards.xlsx`, so the import never changes shape.
 */
class SmartIdesignerZip
{
    private const FOLDERS = [
        'owner' => 'Unit Owner',
        'tenant' => 'Tenant',
        'employee' => 'Employee',
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

        foreach (self::FOLDERS as $type => $folder) {
            $cardsOfType = $this->sorted($cards->where('type', $type), $type);

            $zip->addFromString("{$folder}/cards.xlsx", $this->buildSheet($cardsOfType));

            foreach ($cardsOfType as $card) {
                $zip->addFile(
                    Storage::disk('local')->path($card->person->photo_path),
                    "{$folder}/{$card->control_number}.jpg",
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
    private function buildSheet(Collection $cards): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cards-xlsx').'.xlsx';

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Image', 'Name', 'Unit', 'Code']));

        foreach ($cards as $card) {
            $writer->addRow(new Row([
                Cell::fromValue("{$card->control_number}.jpg"),
                Cell::fromValue($card->person->exportName()),
                Cell::fromValue($card->unit?->unitCode() ?? ''),
                // A text cell, never numeric — Excel silently strips
                // leading zeros from a numeric cell (rule 14).
                new StringCell($card->control_number, null),
            ]));
        }

        $writer->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
