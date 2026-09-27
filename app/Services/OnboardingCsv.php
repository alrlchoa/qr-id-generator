<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * The one CSV parser both bulk-onboarding importers share (Phase 21 plan)
 * — persons and units differ in what their columns mean, not in how a
 * spreadsheet gets turned into rows. `fgetcsv()` over an in-memory stream
 * (not a naive line-split) so a quoted field containing a comma or an
 * embedded newline still parses correctly, the same reason Phase 19's
 * `SmartIdesignerZip` writes CSV by hand rather than trusting a naive
 * approach — reading has the mirror-image problem writing did there.
 *
 * Row numbers reported back are 1-based against the file as sent,
 * matching what a spreadsheet's own row numbers would show (header = row
 * 1) — a fully blank line still consumes a row number even though it's
 * skipped, so "row 7" in an error always means the file's actual row 7.
 */
class OnboardingCsv
{
    private const MAX_BYTES = 1024 * 1024;

    private const MAX_ROWS = 1000;

    /**
     * @param  UploadedFile|string  $file  an uploaded file, or raw CSV contents
     * @param  array<int, string>  $expectedHeaders  canonical header names, matched
     *                                               case-insensitively and in any order
     * @return array<int, array<string, ?string>> data rows keyed by their spreadsheet
     *                                            row number, each keyed by canonical header
     */
    public function parse(UploadedFile|string $file, array $expectedHeaders): array
    {
        $contents = $file instanceof UploadedFile
            ? file_get_contents($file->getRealPath())
            : $file;

        if ($contents === false) {
            throw new InvalidArgumentException('The file could not be read.');
        }

        if (strlen($contents) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The file is larger than the 1 MB limit.');
        }

        // Excel's "CSV UTF-8" export adds a byte-order mark; its plain
        // "CSV" export is Windows-1252, refused below rather than mangled.
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new InvalidArgumentException('The file is not valid UTF-8 — in Excel, use "Save As" → "CSV UTF-8 (Comma delimited)", not plain "CSV".');
        }

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $contents);
        rewind($stream);

        $headerRow = fgetcsv($stream);

        if ($headerRow === false || $headerRow === [null]) {
            fclose($stream);
            throw new InvalidArgumentException('The file has no header row.');
        }

        $columnMap = $this->matchHeaders($headerRow, $expectedHeaders);

        $rows = [];
        $rowNumber = 1;
        $dataRowCount = 0;

        while (($record = fgetcsv($stream)) !== false) {
            $rowNumber++;

            if ($record === [null] || $this->isBlank($record)) {
                continue;
            }

            $dataRowCount++;

            if ($dataRowCount > self::MAX_ROWS) {
                fclose($stream);
                throw new InvalidArgumentException('The file has more than '.self::MAX_ROWS.' data rows — split it into smaller files.');
            }

            $row = [];

            foreach ($columnMap as $header => $index) {
                $value = $record[$index] ?? null;
                $value = $value === null ? null : trim($value);
                $row[$header] = $value === '' ? null : $value;
            }

            $rows[$rowNumber] = $row;
        }

        fclose($stream);

        return $rows;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<string, ?string>>  $rows
     */
    public function buildCsv(array $headers, array $rows): string
    {
        $lines = [$this->csvLine($headers)];

        foreach ($rows as $row) {
            $lines[] = $this->csvLine(array_map(fn ($header) => (string) ($row[$header] ?? ''), $headers));
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @param  array<int, string>  $headerRow
     * @param  array<int, string>  $expectedHeaders
     * @return array<string, int> canonical header => column index
     */
    private function matchHeaders(array $headerRow, array $expectedHeaders): array
    {
        $normalized = [];
        $originalCase = [];

        foreach ($headerRow as $index => $header) {
            $trimmed = trim((string) $header);
            $normalized[mb_strtolower($trimmed)] = $index;
            $originalCase[mb_strtolower($trimmed)] = $trimmed;
        }

        $map = [];
        $missing = [];

        foreach ($expectedHeaders as $canonical) {
            $key = mb_strtolower($canonical);

            if (! array_key_exists($key, $normalized)) {
                $missing[] = $canonical;

                continue;
            }

            $map[$canonical] = $normalized[$key];
            unset($normalized[$key]);
        }

        if ($missing !== []) {
            throw new InvalidArgumentException('The file is missing required column(s): '.implode(', ', $missing).'. Download the template again and use its headers exactly.');
        }

        if ($normalized !== []) {
            $unrecognized = array_map(fn ($key) => $originalCase[$key], array_keys($normalized));
            throw new InvalidArgumentException('The file has unrecognized column(s): '.implode(', ', $unrecognized).'. Download the template again and use its headers exactly.');
        }

        return $map;
    }

    /**
     * @param  array<int, string|null>  $record
     */
    private function isBlank(array $record): bool
    {
        foreach ($record as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Quotes a field only when it actually needs it (a comma, a quote, or
     * a newline) — the same reasoning as `SmartIdesignerZip::csvLine()`:
     * `fputcsv()` also quotes any field containing a space, which puts
     * quotation marks around every ordinary name for no reason.
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
