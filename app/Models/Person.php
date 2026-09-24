<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property string|null $photo_path
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

    /**
     * The person-side name fields on CLAUDE.md rule 10's closed printed-field
     * list — a change to any of them forces reissue of every active card
     * (rule 11). The photo is the one other person-side printed field; its
     * own upload flow triggers the same reissue. Card-level fields (unit,
     * type, position, department, control number) live on `id_cards`, not
     * here. Defined on the model since Phase 14: it used to be a literal
     * inside people/show.blade.php, a Volt class Larastan cannot see (rule 49).
     */
    public const PRINTED_NAME_FIELDS = ['first_name', 'middle_name', 'last_name', 'suffix'];

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
     * "First Middle Last Suffix" — the printed-card format (Phase 12
     * follow-up), deliberately not `displayName()`'s "Last, First Middle
     * Suffix": the UI and a physical printed card are two different
     * rendering contexts with their own established conventions, and this
     * is the second, explicit one, not a replacement for the first.
     * `CardRenderer` is the only caller; the entity_type branch stays here
     * rather than at the call site, per rule 37, even though a company
     * never reaches it in practice (companies never hold cards, rule 36).
     */
    public function printedName(): string
    {
        if ($this->isCompany()) {
            return (string) $this->legal_name;
        }

        return implode(' ', array_filter([
            $this->first_name, $this->middle_name, $this->last_name, $this->suffix,
        ], fn ($part) => filled($part)));
    }

    /**
     * "First Last Suffix" — no middle name — the Name column in the Smart
     * IDesigner import (Phase 19 plan). A third, narrower rendering
     * context alongside `displayName()` and `printedName()`: third-party
     * layout software gets a shorter name than the physical card itself
     * does, deliberately, not by omission. A printed-card-adjacent name
     * for one consumer, not a UI name — rule 37 is unaffected.
     */
    public function exportName(): string
    {
        if ($this->isCompany()) {
            return (string) $this->legal_name;
        }

        return implode(' ', array_filter([
            $this->first_name, $this->last_name, $this->suffix,
        ], fn ($part) => filled($part)));
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

    /**
     * Every natural person, formatted for `<x-person-picker>` — the list
     * the Issue ID and Open Relationship pickers both want. Companies are
     * excluded outright rather than offered and refused server-side: a
     * company can hold no card (rule 36) and no ordinary relationship, so
     * an offered-then-refused option would only ever be a worse error
     * message than not offering it.
     *
     * @return array<int, array{id_number: string, label: string}>
     */
    public static function naturalPickerOptions(): array
    {
        return self::pickerOptions(self::query()->where('entity_type', 'natural'));
    }

    /**
     * Every contactable-tier party (architecture §3), natural or company —
     * the tier a primary owner must already satisfy, which is why the
     * create-unit, transfer-ownership, and designate-primary-owner pickers
     * all want exactly this list.
     *
     * @return array<int, array{id_number: string, label: string}>
     */
    public static function contactablePickerOptions(): array
    {
        return self::pickerOptions(
            self::query()->whereNotNull('mobile_number')->whereNotNull('email')
        );
    }

    /**
     * The one place a person becomes a picker option, so the "ID number -
     * Name" label has a single definition and the name still reaches it
     * only through `displayName()` (rule 37).
     *
     * @param  Builder<Person>  $query
     * @return array<int, array{id_number: string, label: string}>
     */
    private static function pickerOptions(Builder $query): array
    {
        return $query->get()
            ->map(fn (self $person) => [
                'id_number' => $person->user_id_number,
                'label' => "{$person->user_id_number} - {$person->displayName()}",
            ])
            ->values()
            ->all();
    }

    /**
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * @return HasMany<PersonUnitRelationship, $this>
     */
    public function relationships(): HasMany
    {
        return $this->hasMany(PersonUnitRelationship::class);
    }

    /**
     * @return HasMany<IdCard, $this>
     */
    public function idCards(): HasMany
    {
        return $this->hasMany(IdCard::class);
    }
}
