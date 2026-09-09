<?php

namespace App\Models;

use Database\Factories\IdCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read Person $person
 * @property-read Unit|null $unit
 * @property-read Template|null $template
 */
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

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Template, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /**
     * @return BelongsTo<IdCard, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(IdCard::class, 'replaces_id_card_id');
    }

    /**
     * @return HasMany<IdCard, $this>
     */
    public function replacedBy(): HasMany
    {
        return $this->hasMany(IdCard::class, 'replaces_id_card_id');
    }
}
