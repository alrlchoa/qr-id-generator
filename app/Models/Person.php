<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Larastan's model-property extension resolves columns by statically
 * parsing `Schema::create()`/`Schema::table()` calls across every migration
 * file, never by querying a live database. `entity_type` and `legal_name`
 * were added by a migration that also runs raw `DB::statement()` ALTER
 * TABLE calls alongside a `Schema::table()` block (the NOT NULL drops have
 * no Blueprint DSL equivalent without doctrine/dbal, which isn't installed
 * here) — Larastan doesn't parse the raw statements. Declared explicitly so
 * static analysis has the same schema knowledge the database does.
 *
 * @property string $entity_type
 * @property string|null $legal_name
 */
#[Fillable([
    'user_id_number', 'entity_type', 'first_name', 'middle_name', 'last_name', 'suffix', 'legal_name', 'photo_path',
    'date_of_birth', 'place_of_birth', 'gender',
    'home_address', 'mobile_number', 'landline_number', 'email',
    'emergency_contact_name', 'emergency_contact_number', 'emergency_contact_relation',
    'notes',
])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function isNatural(): bool
    {
        return $this->entity_type === 'natural';
    }

    public function isCompany(): bool
    {
        return $this->entity_type === 'company';
    }

    /**
     * The only name-rendering path in the system (architecture §3, CLAUDE.md
     * rule 37) — nothing outside the model layer branches on `entity_type`
     * to render a name.
     */
    public function displayName(): string
    {
        return $this->isCompany() ? (string) $this->legal_name : $this->fullName();
    }

    /**
     * Natural-person-only composed name, "Last, First Middle Suffix" —
     * `display_name()` is what the UI calls; this stays the internal
     * helper for the `natural` case.
     */
    public function fullName(): string
    {
        $given = implode(' ', array_filter([
            $this->first_name, $this->middle_name, $this->suffix,
        ], fn ($part) => filled($part)));

        return trim("{$this->last_name}, {$given}", ', ');
    }

    /**
     * Architecture §3 "Profile completeness" — Contactable tier: the above
     * (a name for the kind, already guaranteed by the DB check constraint)
     * plus mobile_number and email. Required to be a primary unit owner.
     */
    public function isContactable(): bool
    {
        return filled($this->mobile_number) && filled($this->email);
    }

    /**
     * Cardable tier: natural persons only — a company can never reach this
     * tier (architecture §3, CLAUDE.md rule 36). The three tiers are
     * cumulative, so cardable also requires the contactable fields, not
     * just a photo on top of the minimal name.
     */
    public function isCardable(): bool
    {
        return $this->isNatural() && $this->isContactable() && filled($this->photo_path);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(PersonUnitRelationship::class);
    }

    public function idCards(): HasMany
    {
        return $this->hasMany(IdCard::class);
    }
}
