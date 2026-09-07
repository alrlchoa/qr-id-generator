<?php

namespace App\Models;

use Database\Factories\PersonUnitRelationshipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['person_id', 'unit_id', 'type', 'is_primary_owner', 'start_date', 'contract_end_date', 'ended_at'])]
class PersonUnitRelationship extends Model
{
    /** @use HasFactory<PersonUnitRelationshipFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_primary_owner' => 'bool',
            'start_date' => 'date',
            'contract_end_date' => 'date',
            'ended_at' => 'datetime',
        ];
    }

    public function isOwner(): bool
    {
        return $this->type === 'owner';
    }

    /**
     * `ended_at IS NULL` is the sole source of truth for activity.
     * `contract_end_date` is paperwork and plays no part here (§7).
     */
    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
