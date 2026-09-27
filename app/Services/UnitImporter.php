<?php

namespace App\Services;

use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Units half of Phase 21's bulk onboarding. Every row is created
 * through `UnitLifecycleManager::createUnit()` — the same service the
 * Create Unit form uses — so the primary-owner relationship, the tier
 * check, and the audit rows are identical to entering it by hand (rule
 * 43's reasoning, applied here instead of to `audit_logs` directly).
 * Co-owners, tenants, photos and card issuance are out of scope; a unit
 * row here is exactly its code and its primary owner.
 */
class UnitImporter
{
    public const HEADERS = ['Building Code', 'Floor', 'Unit Number', 'Primary Owner ID Number', 'Date First Owned'];

    public function __construct(
        private readonly OnboardingCsv $csv,
        private readonly UnitLifecycleManager $units,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function templateCsv(): string
    {
        return $this->csv->buildCsv(self::HEADERS, []);
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, hasErrors: bool}
     */
    public function preview(UploadedFile|string $file): array
    {
        return $this->validateRows($this->csv->parse($file, self::HEADERS));
    }

    /**
     * @return array{count: int}
     */
    public function import(User $actor, UploadedFile|string $file, string $originalFilename): array
    {
        return DB::transaction(function () use ($actor, $file, $originalFilename) {
            // Re-parsed and re-validated inside the transaction, same
            // reasoning as PersonImporter::import() — a unit code or an
            // owner's eligibility can change between preview and import.
            $validated = $this->validateRows($this->csv->parse($file, self::HEADERS));

            if ($validated['hasErrors']) {
                throw new InvalidArgumentException('The file has errors — nothing was imported. Re-check it and try again.');
            }

            $count = 0;

            foreach ($validated['rows'] as $row) {
                $this->units->createUnit(
                    $actor,
                    $row['unitAttributes'],
                    ['person_id' => $row['ownerId']],
                    $row['startDate'],
                );
                $count++;
            }

            $this->auditLogger->log(
                actor: $actor,
                action: 'bulk_onboarding_imported',
                subject: $actor,
                newValue: ['kind' => 'units', 'rows' => $count, 'filename' => $originalFilename],
            );

            return ['count' => $count];
        });
    }

    /**
     * @param  array<int, array<string, ?string>>  $rows
     * @return array{rows: array<int, array<string, mixed>>, hasErrors: bool}
     */
    private function validateRows(array $rows): array
    {
        $perRow = [];

        foreach ($rows as $rowNumber => $data) {
            $perRow[$rowNumber] = $this->validateRow($data);
        }

        $rowNumbers = array_keys($perRow);

        for ($i = 0; $i < count($rowNumbers); $i++) {
            for ($j = $i + 1; $j < count($rowNumbers); $j++) {
                $a = $rowNumbers[$i];
                $b = $rowNumbers[$j];

                if ($perRow[$a]['normalizedCode'] === null || $perRow[$b]['normalizedCode'] === null) {
                    continue;
                }

                if ($perRow[$a]['normalizedCode'] !== $perRow[$b]['normalizedCode']) {
                    continue;
                }

                $perRow[$a]['errors'][] = "Duplicate of row {$b} — same unit code.";
                $perRow[$b]['errors'][] = "Duplicate of row {$a} — same unit code.";
            }
        }

        $hasErrors = false;
        $out = [];

        foreach ($perRow as $rowNumber => $row) {
            if ($row['errors'] !== []) {
                $hasErrors = true;
            }

            $out[$rowNumber] = [
                'data' => $row['data'],
                'errors' => $row['errors'],
                'warnings' => [],
                'unitAttributes' => $row['unitAttributes'],
                'ownerId' => $row['ownerId'],
                'startDate' => $row['startDate'],
            ];
        }

        return ['rows' => $out, 'hasErrors' => $hasErrors];
    }

    /**
     * @param  array<string, ?string>  $data
     * @return array{data: array<string, ?string>, errors: array<int, string>, normalizedCode: ?string, unitAttributes: array<string, ?string>, ownerId: ?int, startDate: ?string}
     */
    private function validateRow(array $data): array
    {
        $errors = [];

        $buildingCode = $data['Building Code'];
        $floor = $data['Floor'];
        $unitNumber = $data['Unit Number'];
        $ownerIdNumber = $data['Primary Owner ID Number'];
        $startDate = $data['Date First Owned'];

        if (filled($buildingCode) && ! preg_match('/^[A-Za-z]$/', $buildingCode)) {
            $errors[] = 'Building Code must be a single letter.';
        }

        if (blank($floor)) {
            $errors[] = 'Floor is required.';
        } elseif (! preg_match('/^[A-Za-z0-9]{1,2}$/', $floor)) {
            $errors[] = 'Floor must be 1-2 letters or digits.';
        }

        if (blank($unitNumber)) {
            $errors[] = 'Unit Number is required.';
        } elseif (! preg_match('/^[0-9]{1,2}$/', $unitNumber)) {
            $errors[] = 'Unit Number must be 1-2 digits.';
        }

        if (blank($startDate)) {
            $errors[] = 'Date First Owned is required.';
        } elseif (! $this->isIsoDate($startDate)) {
            $errors[] = 'Date First Owned must be YYYY-MM-DD.';
        }

        $ownerId = null;

        if (blank($ownerIdNumber)) {
            $errors[] = 'Primary Owner ID Number is required.';
        } else {
            // Excel strips leading zeros from a number-looking cell —
            // padded back the same way §6 already pads admin-typed lookups.
            $paddedOwnerId = str_pad(preg_replace('/\D+/', '', $ownerIdNumber), 8, '0', STR_PAD_LEFT);

            if (! preg_match('/^[0-9]{8}$/', $paddedOwnerId)) {
                $errors[] = 'Primary Owner ID Number must be 8 digits.';
            } else {
                $owner = Person::withTrashed()->where('user_id_number', $paddedOwnerId)->first();

                if ($owner === null) {
                    $errors[] = "No person with ID number {$paddedOwnerId} exists.";
                } elseif ($owner->trashed()) {
                    $errors[] = "Person {$paddedOwnerId} is deleted — restore them before using them as a primary owner.";
                } elseif (! $owner->isContactable()) {
                    $errors[] = "Person {$paddedOwnerId} ({$owner->displayName()}) is missing a mobile number or email — required to be a primary owner.";
                } else {
                    $ownerId = $owner->id;
                }
            }
        }

        $buildingValid = blank($buildingCode) || preg_match('/^[A-Za-z]$/', $buildingCode);
        $floorValid = filled($floor) && preg_match('/^[A-Za-z0-9]{1,2}$/', $floor);
        $unitNumberValid = filled($unitNumber) && preg_match('/^[0-9]{1,2}$/', $unitNumber);

        $normalizedCode = ($buildingValid && $floorValid && $unitNumberValid)
            ? mb_strtoupper((string) $buildingCode).str_pad(mb_strtoupper($floor), 2, '0', STR_PAD_LEFT).str_pad($unitNumber, 2, '0', STR_PAD_LEFT)
            : null;

        if ($normalizedCode !== null) {
            $existing = Unit::withTrashed()->get()->first(fn (Unit $unit) => mb_strtoupper((string) $unit->building_code).$unit->floor_code.$unit->unit_number === $normalizedCode);

            if ($existing !== null && $existing->trashed()) {
                $errors[] = "Unit code {$normalizedCode} belongs to a deleted unit — restore it instead of importing again.";
            } elseif ($existing !== null) {
                $errors[] = "Unit code {$normalizedCode} already exists.";
            }
        }

        return [
            'data' => $data,
            'errors' => $errors,
            'normalizedCode' => $normalizedCode,
            'unitAttributes' => [
                'building_code' => filled($buildingCode) ? $buildingCode : null,
                'floor_code' => $floor,
                'unit_number' => $unitNumber,
            ],
            'ownerId' => $ownerId,
            'startDate' => $startDate,
        ];
    }

    private function isIsoDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $parts = explode('-', $value);

        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }
}
