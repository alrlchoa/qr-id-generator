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
}
