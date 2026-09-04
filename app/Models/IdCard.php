<?php

namespace App\Models;

use Database\Factories\IdCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'person_id', 'unit_id', 'control_number', 'type', 'status',
    'replacement_reason', 'replaces_id_card_id', 'template_id',
    'position', 'department', 'issued_at',
])]
class IdCard extends Model
{
    /** @use HasFactory<IdCardFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }

    /**
     * `status = 'active'` is the single source of truth — never a stored
     * boolean, never derived from a date (§3/§7).
     */
    public function isValid(): bool
    {
        return $this->status === 'active';
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'replaces_id_card_id');
    }

    public function replacedBy(): HasMany
    {
        return $this->hasMany(IdCard::class, 'replaces_id_card_id');
    }
}
