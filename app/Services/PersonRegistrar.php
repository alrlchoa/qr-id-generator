<?php

namespace App\Services;

use App\Models\Person;
use App\Models\User;

/**
 * The one call site that creates a `people` row from already-validated,
 * already-normalized attributes (Phase 21 plan) — minting the ID number
 * and writing `person_created` (rule 43) in one place, so the Create
 * Person page and the bulk persons importer can't drift into two shapes
 * for "what happens when a person is created." Validation and attribute
 * normalization stay with the caller: a Livewire form's rules and a CSV
 * row's rules are different enough (Livewire's `Rule::requiredIf`
 * conditionals vs. row-numbered CSV errors) that forcing them through one
 * shared validator would be the wrong abstraction, per the same "don't
 * force convergence that isn't there" judgment `PersonIdNumberGenerator`
 * already applies to `entity_type`.
 */
class PersonRegistrar
{
    public function __construct(
        private readonly PersonIdNumberGenerator $ids,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Person::create()-shaped, already validated and normalized
     */
    public function register(?User $actor, array $attributes, ?string $actingAs = null): Person
    {
        $person = $this->ids->createWithUniqueId($attributes);

        $this->auditLogger->log(
            actor: $actor,
            action: 'person_created',
            subject: $person,
            newValue: ['entity_type' => $person->entity_type, 'display_name' => $person->displayName()],
            actingAs: $actingAs,
        );

        return $person;
    }
}
