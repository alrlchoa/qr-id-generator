<?php

namespace App\Services;

use App\Models\Person;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The Persons half of Phase 21's bulk onboarding. Every row imports as
 * `entity_type = 'natural'` (§3, §35) — onboarding a company by CSV was
 * declined at kickoff (2026-09-27); a company primary owner is still
 * onboarded the ordinary way and referenced by ID number from the units
 * import. `preview()` and `import()` share `validateRows()` so what a
 * Superadmin previewed is exactly what gets checked again at import time
 * (rule: never trust the preview, the plan's own words) — the only
 * difference is `import()` re-reads the file and re-runs it a second
 * time, inside the transaction, to catch a duplicate created in the
 * meantime.
 */
class PersonImporter
{
    public const HEADERS = [
        'First Name', 'Last Name', 'Email', 'Phone Number',
        'Middle Name', 'Suffix', 'Gender', 'Date of Birth', 'Place of Birth',
        'Home Address', 'Landline Number',
        'Emergency Contact Name', 'Emergency Contact Number', 'Emergency Contact Relation',
        'Notes',
    ];

    private const GENDERS = ['male', 'female', 'prefer_not_to_say'];

    public function __construct(
        private readonly OnboardingCsv $csv,
        private readonly PersonRegistrar $registrar,
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
     * @return array{results: array<int, array{data: array<string, mixed>, id_number: string}>, csv: string}
     */
    public function import(User $actor, UploadedFile|string $file, string $originalFilename): array
    {
        return DB::transaction(function () use ($actor, $file, $originalFilename) {
            // Re-parsed and re-validated inside the transaction — the
            // preview the Superadmin saw is never trusted at write time,
            // since another admin may have created a colliding person in
            // the meantime.
            $validated = $this->validateRows($this->csv->parse($file, self::HEADERS));

            if ($validated['hasErrors']) {
                throw new InvalidArgumentException('The file has errors — nothing was imported. Re-check it and try again.');
            }

            $results = [];

            foreach ($validated['rows'] as $rowNumber => $row) {
                $person = $this->registrar->register($actor, $row['attributes']);
                $results[$rowNumber] = ['data' => $row['data'], 'id_number' => $person->user_id_number];
            }

            $this->auditLogger->log(
                actor: $actor,
                action: 'bulk_onboarding_imported',
                subject: $actor,
                newValue: ['kind' => 'persons', 'rows' => count($results), 'filename' => $originalFilename],
            );

            $csvRows = [];

            foreach ($results as $result) {
                $csvRows[] = [...$result['data'], 'ID Number' => $result['id_number']];
            }

            return [
                'results' => $results,
                'csv' => $this->csv->buildCsv([...self::HEADERS, 'ID Number'], $csvRows),
            ];
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

        // Pairwise, so every row in a duplicate set is flagged — not just
        // whichever one happens to be read second.
        $rowNumbers = array_keys($perRow);

        for ($i = 0; $i < count($rowNumbers); $i++) {
            for ($j = $i + 1; $j < count($rowNumbers); $j++) {
                $a = $rowNumbers[$i];
                $b = $rowNumbers[$j];

                if ($perRow[$a]['normalized']['name'] === '' || $perRow[$b]['normalized']['name'] === '') {
                    continue;
                }

                if ($perRow[$a]['normalized']['name'] !== $perRow[$b]['normalized']['name']) {
                    continue;
                }

                if ($this->sharesContact($perRow[$a]['normalized'], $perRow[$b]['normalized'])) {
                    $perRow[$a]['errors'][] = "Duplicate of row {$b} — same name and contact detail.";
                    $perRow[$b]['errors'][] = "Duplicate of row {$a} — same name and contact detail.";
                } else {
                    $perRow[$a]['warnings'][] = "Possible duplicate of row {$b} — same name, no shared contact detail.";
                    $perRow[$b]['warnings'][] = "Possible duplicate of row {$a} — same name, no shared contact detail.";
                }
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
                'warnings' => $row['warnings'],
                'attributes' => $row['attributes'],
            ];
        }

        return ['rows' => $out, 'hasErrors' => $hasErrors];
    }

    /**
     * @param  array<string, ?string>  $data
     * @return array{data: array<string, ?string>, errors: array<int, string>, warnings: array<int, string>, normalized: array{name: string, email: ?string, phone: ?string}, attributes: array<string, mixed>}
     */
    private function validateRow(array $data): array
    {
        $errors = [];
        $warnings = [];

        $firstName = $data['First Name'];
        $lastName = $data['Last Name'];
        $email = $data['Email'];
        $phone = $data['Phone Number'];

        if (blank($firstName)) {
            $errors[] = 'First Name is required.';
        }

        if (blank($lastName)) {
            $errors[] = 'Last Name is required.';
        }

        if (filled($email) && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is not a valid address.';
        }

        $gender = null;

        if (filled($data['Gender'])) {
            $key = str_replace(' ', '_', mb_strtolower(trim($data['Gender'])));

            if (! in_array($key, self::GENDERS, true)) {
                $errors[] = 'Gender must be Male, Female, or Prefer not to say.';
            } else {
                $gender = $key;
            }
        }

        $dateOfBirth = null;

        if (filled($data['Date of Birth'])) {
            if (! $this->isIsoDate($data['Date of Birth'])) {
                $errors[] = 'Date of Birth must be YYYY-MM-DD.';
            } else {
                $dateOfBirth = $data['Date of Birth'];
            }
        }

        $normalizedName = blank($firstName) || blank($lastName) ? '' : $this->normalizeName($firstName, $lastName);
        $normalizedEmail = filled($email) ? mb_strtolower(trim($email)) : null;
        $normalizedPhone = filled($phone) ? preg_replace('/\D+/', '', $phone) : null;

        if ($errors === [] && $normalizedName !== '') {
            $this->checkAgainstDatabase($normalizedName, $normalizedEmail, $normalizedPhone, $errors, $warnings);
        }

        return [
            'data' => $data,
            'errors' => $errors,
            'warnings' => $warnings,
            'normalized' => ['name' => $normalizedName, 'email' => $normalizedEmail, 'phone' => $normalizedPhone],
            'attributes' => [
                'entity_type' => 'natural',
                'first_name' => $firstName,
                'middle_name' => $data['Middle Name'],
                'last_name' => $lastName,
                'suffix' => $data['Suffix'],
                'date_of_birth' => $dateOfBirth,
                'place_of_birth' => $data['Place of Birth'],
                'gender' => $gender,
                'home_address' => $data['Home Address'],
                'mobile_number' => $phone,
                'landline_number' => $data['Landline Number'],
                'email' => $email,
                'emergency_contact_name' => $data['Emergency Contact Name'],
                'emergency_contact_number' => $data['Emergency Contact Number'],
                'emergency_contact_relation' => $data['Emergency Contact Relation'],
                'notes' => $data['Notes'],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    private function checkAgainstDatabase(string $normalizedName, ?string $normalizedEmail, ?string $normalizedPhone, array &$errors, array &$warnings): void
    {
        $existingMatches = Person::withTrashed()
            ->where('entity_type', 'natural')
            ->get()
            ->filter(fn (Person $person) => $this->normalizeName($person->first_name, $person->last_name) === $normalizedName);

        foreach ($existingMatches as $existing) {
            $existingNormalized = [
                'email' => filled($existing->email) ? mb_strtolower(trim($existing->email)) : null,
                'phone' => filled($existing->mobile_number) ? preg_replace('/\D+/', '', $existing->mobile_number) : null,
            ];

            $shares = $this->sharesContact(['email' => $normalizedEmail, 'phone' => $normalizedPhone], $existingNormalized);

            if ($shares && $existing->trashed()) {
                $errors[] = "Matches a deleted person, {$existing->user_id_number} — same name and contact detail. Restore that person instead of importing again.";
            } elseif ($shares) {
                $errors[] = "Duplicate of an existing person, {$existing->user_id_number} — same name and contact detail. Use that ID number instead of importing again.";
            } elseif ($existing->trashed()) {
                $warnings[] = "Matches a deleted person's name, {$existing->user_id_number} — no shared contact detail, not blocked.";
            } else {
                $warnings[] = "Matches an existing person's name, {$existing->user_id_number} — no shared contact detail, not blocked.";
            }
        }
    }

    /**
     * @param  array{email: ?string, phone: ?string}  $a
     * @param  array{email: ?string, phone: ?string}  $b
     */
    private function sharesContact(array $a, array $b): bool
    {
        return ($a['email'] !== null && $a['email'] === $b['email'])
            || ($a['phone'] !== null && $a['phone'] === $b['phone']);
    }

    private function normalizeName(?string $first, ?string $last): string
    {
        $collapse = fn (?string $value) => trim(preg_replace('/\s+/', ' ', (string) $value));

        return mb_strtolower(trim($collapse($first).' '.$collapse($last)));
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
