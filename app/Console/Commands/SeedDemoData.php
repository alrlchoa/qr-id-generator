<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\Unit;
use App\Services\AuditLogger;
use App\Services\PersonIdNumberGenerator;
use App\Services\RelationshipManager;
use App\Services\UnitLifecycleManager;
use App\Support\DemoFaker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Realistic browsing/sorting test data — people, companies, units, and the
 * relationships between them. **Never touches `users`.** Console-originated
 * (rule 44's `actingAs: 'console'`), same as the Superadmin commands, so
 * every row it writes still lands in `audit_logs` through the one call site
 * (rule 43) rather than a seeder-specific shortcut.
 *
 * Not gated to `local` the way `DatabaseSeeder` is (rule 25's gate is
 * specifically "no seeder creates a default account," and this one creates
 * none) — it's meant to be run once against a freshly deployed system for
 * visual/optical testing. `--force` is required outside `local` as the
 * same kind of guardrail `migrate --force` uses, not a hard block.
 *
 * Uses `App\Support\DemoFaker`, not FakerPHP's `fake()` — `fakerphp/faker`
 * is deliberately `require-dev` only (composer.json), and `deploy.sh` runs
 * `composer install --no-dev`, so `fake()` is genuinely absent on every
 * real deployment this command is meant to run on. Discovered the hard
 * way: this command originally used `fake()` and failed with "Call to
 * undefined function" the first time it ran on a deployed box.
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed-test-data {--force : Required to run outside the local environment}';

    protected $description = 'Seed people, companies, and units with realistic test data for browsing and sorting. Never writes to the users table.';

    private const ACTING_AS = 'console';

    public function handle(
        PersonIdNumberGenerator $ids,
        UnitLifecycleManager $units,
        RelationshipManager $relationships,
        AuditLogger $auditLogger,
    ): int {
        if (! app()->environment('local') && ! $this->option('force')) {
            $this->components->error('Refusing to run outside the local environment without --force. This writes real rows to people, units, and person_unit_relationships (never users).');

            return self::FAILURE;
        }

        $this->components->info('Seeding companies...');
        $companies = collect(range(1, 6))->map(function () use ($ids, $auditLogger) {
            $name = DemoFaker::companyName();

            return $this->createPerson($ids, $auditLogger, [
                'entity_type' => 'company',
                'legal_name' => $name,
                'mobile_number' => DemoFaker::phoneNumber(),
                'email' => DemoFaker::email($name),
                'home_address' => DemoFaker::address(),
            ]);
        });

        $this->components->info('Seeding natural persons...');

        // Minimal tier: a name and nothing else — a finished, valid record
        // (CLAUDE.md rule 33), not a draft.
        $minimal = collect(range(1, 16))->map(fn () => $this->createPerson($ids, $auditLogger, $this->naturalPersonAttributes()));

        // Contactable tier: + mobile + email, eligible to be a primary owner.
        $contactable = collect(range(1, 22))->map(function () use ($ids, $auditLogger) {
            $attributes = $this->naturalPersonAttributes();

            return $this->createPerson($ids, $auditLogger, [
                ...$attributes,
                'mobile_number' => DemoFaker::phoneNumber(),
                'email' => DemoFaker::email($attributes['first_name'].'.'.$attributes['last_name']),
            ]);
        });

        // Cardable tier: + a real photo on the private disk, through the
        // same pipeline PersonPhotoController serves from — so these render
        // for real during optical testing, not just as a photo_path string.
        $cardable = collect(range(1, 16))->map(function () use ($ids, $auditLogger) {
            $natural = $this->naturalPersonAttributes();
            $attributes = [
                ...$natural,
                'mobile_number' => DemoFaker::phoneNumber(),
                'email' => DemoFaker::email($natural['first_name'].'.'.$natural['last_name']),
            ];
            $person = $this->createPerson($ids, $auditLogger, $attributes);
            $this->attachGeneratedPhoto($person, $auditLogger);

            return $person;
        });

        $this->components->info(sprintf(
            'Created %d companies and %d natural persons (%d minimal, %d contactable, %d cardable).',
            $companies->count(), $minimal->count() + $contactable->count() + $cardable->count(),
            $minimal->count(), $contactable->count(), $cardable->count(),
        ));

        $this->components->info('Seeding units...');

        // Contactable is the pool every primary owner (natural or company)
        // is drawn from — architecture §3 requires it.
        $ownerPool = $contactable->concat($cardable)->concat($companies)->shuffle();

        // 3 entities each own 2 units; 6 more own 1 each — 9 distinct
        // owners across 12 units, comfortably past "at least 10 units" and
        // "at least 3 entities with multiple units."
        $multiOwners = $ownerPool->splice(0, 3);
        $singleOwners = $ownerPool->splice(0, 6);

        $unitCodes = $this->generateUnitCodes(12);
        $createdUnits = collect();

        foreach ($multiOwners as $owner) {
            for ($i = 0; $i < 2; $i++) {
                $createdUnits->push($this->createUnitForOwner($units, $owner, array_shift($unitCodes)));
            }
        }

        foreach ($singleOwners as $owner) {
            $createdUnits->push($this->createUnitForOwner($units, $owner, array_shift($unitCodes)));
        }

        $this->components->info("Created {$createdUnits->count()} units ({$multiOwners->count()} owners hold two each).");

        $this->components->info('Opening co-owner and tenant relationships...');

        $occupantPool = $minimal->concat($contactable)->shuffle()->values();
        $occupantIndex = 0;
        $openedCount = 0;
        $closedCount = 0;

        foreach ($createdUnits as $unit) {
            $occupantCount = DemoFaker::numberBetween(0, 3);

            for ($i = 0; $i < $occupantCount && $occupantIndex < $occupantPool->count(); $i++) {
                $occupant = $occupantPool[$occupantIndex++];
                $type = DemoFaker::pick(['tenant', 'tenant', 'owner']);

                $relationship = $relationships->openRelationship(
                    actor: null,
                    person: $occupant,
                    unit: $unit,
                    type: $type,
                    startDate: DemoFaker::pastDate('-2 years', '-1 month'),
                    contractEndDate: $type === 'tenant' ? DemoFaker::optional(50, fn () => DemoFaker::futureDate()) : null,
                    actingAs: self::ACTING_AS,
                );
                $openedCount++;

                // A handful ended already, so the index shows both statuses
                // and the "active relationship, no photo" filter has real
                // history to sort through, not just a wall of "Active."
                if (DemoFaker::boolean(20)) {
                    $relationships->closeRelationship(null, $relationship, self::ACTING_AS);
                    $closedCount++;
                }
            }
        }

        $this->components->info("Opened {$openedCount} additional relationships ({$closedCount} already closed for variety).");
        $this->components->info('Done. Nothing was written to the users table.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function naturalPersonAttributes(): array
    {
        return [
            'entity_type' => 'natural',
            'first_name' => DemoFaker::firstName(),
            'middle_name' => DemoFaker::optional(60, fn () => DemoFaker::lastName()),
            'last_name' => DemoFaker::lastName(),
            'suffix' => DemoFaker::optional(5, fn () => DemoFaker::suffix()),
            'home_address' => DemoFaker::optional(70, fn () => DemoFaker::address()),
            'date_of_birth' => DemoFaker::optional(60, fn () => DemoFaker::pastDate('-70 years', '-18 years')),
            'gender' => DemoFaker::optional(60, fn () => DemoFaker::gender()),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPerson(PersonIdNumberGenerator $ids, AuditLogger $auditLogger, array $attributes): Person
    {
        $person = $ids->createWithUniqueId($attributes);

        $auditLogger->log(
            actor: null,
            action: 'person_created',
            subject: $person,
            newValue: ['entity_type' => $person->entity_type, 'display_name' => $person->displayName()],
            actingAs: self::ACTING_AS,
        );

        return $person;
    }

    /**
     * A small solid-color initials avatar via GD — no upload machinery
     * needed, but stored through the exact disk/path shape
     * `PersonPhotoController` serves from, so it renders for real.
     */
    private function attachGeneratedPhoto(Person $person, AuditLogger $auditLogger): void
    {
        $image = imagecreatetruecolor(200, 200);
        $seed = crc32($person->displayName());
        $color = imagecolorallocate($image, ($seed & 0xFF0000) >> 16, ($seed & 0x00FF00) >> 8, $seed & 0x0000FF);
        imagefilledrectangle($image, 0, 0, 199, 199, $color);

        $white = imagecolorallocate($image, 255, 255, 255);
        $initials = strtoupper(mb_substr((string) $person->first_name, 0, 1).mb_substr((string) $person->last_name, 0, 1));
        imagestring($image, 5, 90, 95, $initials, $white);

        ob_start();
        imagejpeg($image, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $path = 'people-photos/'.Str::uuid()->toString().'.jpg';
        Storage::disk('local')->put($path, $bytes);

        $person->forceFill(['photo_path' => $path])->save();

        $auditLogger->log(
            actor: null,
            action: 'photo_updated',
            subject: $person,
            previousValue: ['had_photo' => false],
            newValue: ['had_photo' => true],
            actingAs: self::ACTING_AS,
        );
    }

    private function createUnitForOwner(UnitLifecycleManager $units, Person $owner, array $unitAttributes): Unit
    {
        $result = $units->createUnit(
            actor: null,
            unitAttributes: $unitAttributes,
            primaryOwner: ['person_id' => $owner->id],
            startDate: DemoFaker::pastDate('-3 years', '-6 months'),
            actingAs: self::ACTING_AS,
        );

        return $result['unit'];
    }

    /**
     * @return array<int, array{building_code: string, floor_code: string, unit_number: string}>
     */
    private function generateUnitCodes(int $count): array
    {
        $codes = [];

        foreach (['A', 'B'] as $building) {
            foreach (['01', '02', '03'] as $floor) {
                foreach (['01', '02'] as $number) {
                    $codes[] = ['building_code' => $building, 'floor_code' => $floor, 'unit_number' => $number];

                    if (count($codes) >= $count) {
                        return $codes;
                    }
                }
            }
        }

        return $codes;
    }
}
