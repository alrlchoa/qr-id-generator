<?php

namespace App\Models;

use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string|null $building_code
 * @property string $floor_code
 * @property string $unit_number
 */
#[Fillable(['building_code', 'floor_code', 'unit_number'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
    use HasFactory, SoftDeletes;

    protected function buildingCode(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value,
            set: fn (?string $value) => $value === null ? null : strtoupper($value),
        );
    }

    protected function floorCode(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => $value,
            set: fn (string $value) => str_pad(strtoupper($value), 2, '0', STR_PAD_LEFT),
        );
    }

    protected function unitNumber(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => $value,
            set: fn (string $value) => str_pad($value, 2, '0', STR_PAD_LEFT),
        );
    }

    /**
     * The full ABBCC code — never stored, always derived from the parts.
     */
    public function unitCode(): string
    {
        return ($this->building_code ?? '').$this->floor_code.$this->unit_number;
    }

    public function relationships(): HasMany
    {
        return $this->hasMany(PersonUnitRelationship::class);
    }

    public function idCards(): HasMany
    {
        return $this->hasMany(IdCard::class);
    }

    /**
     * Every active relationship, `whereNull('ended_at')` (§7) — the only
     * activity test used anywhere in this codebase.
     */
    public function activeRelationships(): HasMany
    {
        return $this->relationships()->whereNull('ended_at');
    }

    /**
     * Guaranteed to exist and be unique for a live unit by the partial
     * unique index + the application-layer "at least one" check (§3, §5.4)
     * — null only means the integrity canary (architecture §14 Query D) has
     * something to report.
     */
    public function primaryOwnerRelationship(): ?PersonUnitRelationship
    {
        return PersonUnitRelationship::where('unit_id', $this->id)
            ->whereNull('ended_at')
            ->where('is_primary_owner', true)
            ->first();
    }

    public function primaryOwnerPersonId(): ?int
    {
        return $this->primaryOwnerRelationship()?->person_id;
    }

    /**
     * §5.2's six-slot count: active owner/tenant relationships belonging to
     * anyone other than the primary owner. The primary owner's own slot is
     * reserved and never part of this number, whether or not they use it.
     */
    public function nonPrimaryOwnerActiveRelationships(): HasMany
    {
        return $this->activeRelationships()->where('person_id', '!=', $this->primaryOwnerPersonId());
    }

    /**
     * §5.2's cap counted at the relationship layer: active owner/tenant
     * relationships that are not the reserved primary-owner one. The
     * six-slot cap binds here as well as on cards (added 2026-09-09) —
     * a seventh occupant can no longer be *recorded* and then merely fail
     * to be carded.
     *
     * Scoped on `is_primary_owner`, not on `person_id !=
     * primaryOwnerPersonId()` the way `nonPrimaryOwnerActiveRelationships()`
     * is: those two agree for every live unit, but a unit whose primary
     * owner is somehow missing (architecture §14 Query D's canary) makes
     * the person_id form compare against null, which matches no rows in
     * SQL and would silently report a capacity of zero on the one unit
     * already known to be broken.
     */
    public function nonPrimaryOwnerActiveRelationshipCount(): int
    {
        return $this->activeRelationships()
            ->whereIn('type', ['owner', 'tenant'])
            ->where('is_primary_owner', false)
            ->count();
    }

    /**
     * §5.2's cap as it applies to *cards* — narrower than the relationship
     * count above, because a relationship can be open with no card issued
     * yet. Employee cards never count (`type IN ('owner', 'tenant')` only).
     */
    public function nonPrimaryOwnerActiveCardCount(): int
    {
        return $this->idCards()
            ->where('status', 'active')
            ->whereIn('type', ['owner', 'tenant'])
            ->where('person_id', '!=', $this->primaryOwnerPersonId())
            ->count();
    }
}
