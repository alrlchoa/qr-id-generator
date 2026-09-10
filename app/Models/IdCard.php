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
            'printed_at' => 'datetime',
        ];
    }

    /**
     * Orthogonal to `status` — a lost, revoked, or expired card can still
     * have been printed once, and printing never changes `status`. Never
     * in `Fillable`: the only writer is `CardPrintService::print()`, via
     * `forceFill()`, the same shape `status` transitions already use
     * regardless of their own `Fillable` membership.
     */
    public function isPrinted(): bool
    {
        return $this->printed_at !== null;
    }

    /**
     * The card-type label printed in a card's `role` field — the single
     * source of truth `CardRenderer` and the template placement editor
     * both call, rather than each keeping their own copy of this mapping.
     * Takes a plain `type`/`id_type` string, not `$this`, since the
     * template editor needs the same label for a `Template::id_type`
     * before any card exists yet.
     */
    public static function roleLabelFor(string $type): string
    {
        return match ($type) {
            'owner' => 'Unit Owner',
            'tenant' => 'Tenant',
            'employee' => 'Employee',
            default => ucfirst($type),
        };
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
