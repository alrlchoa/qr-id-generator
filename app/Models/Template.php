<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 12: a CR80 card design, front and back, per `id_type`
 * (owner/tenant/employee — the same three values `id_cards.type` carries).
 *
 * `width_px`/`height_px` are set once at creation and never updated after —
 * that is the entire enforcement for "orientation can't change once
 * artwork or positions exist" (a Phase 12 trap): the operation simply
 * doesn't exist, rather than needing special-case clearing logic. A
 * different orientation means a new template row.
 *
 * `overlay_path_front`/`overlay_path_back` hold the uploaded PNG, composited
 * *last*, on top of every field — an overlay, not a background — so a
 * cut-out in the artwork frames the photo as a border. `field_positions_back`
 * stays null always: the back carries no placeable fields (Phase 12 plan).
 *
 * At most one template per `id_type` may be `is_active` at a time
 * (`uq_templates_active_per_id_type`, a partial unique index — the same
 * "at most one" pattern rule 30 uses for primary owners). `activeFor()` is
 * what issuance and card rendering resolve against.
 *
 * @property string|null $overlay_path_front
 * @property string|null $overlay_path_back
 * @property array<string, array{x: int, y: int, width: int, height: int}>|null $field_positions_front
 * @property array<string, array{x: int, y: int, width: int, height: int}>|null $field_positions_back
 */
#[Fillable([
    'id_type', 'name', 'overlay_path_front', 'overlay_path_back',
    'width_px', 'height_px', 'field_positions_front', 'field_positions_back', 'is_active',
])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    public const ID_TYPES = ['owner', 'tenant', 'employee'];

    public const ORIENTATION_LANDSCAPE = 'landscape';

    public const ORIENTATION_PORTRAIT = 'portrait';

    private const LANDSCAPE_WIDTH = 1011;

    private const LANDSCAPE_HEIGHT = 638;

    /**
     * The five placeable front fields — owner and tenant templates carry
     * all five; employee carries four, since an employee card names no
     * unit (rule 31, `IssuanceManager::issueEmployeeCard()` always sets
     * `unit_id = null`) and a unit field would render blank every time.
     *
     * @return list<string>
     */
    public static function placeableFieldsFor(string $idType): array
    {
        return $idType === 'employee'
            ? ['photo', 'name', 'qr', 'role']
            : ['photo', 'name', 'unit_number', 'qr', 'role'];
    }

    /**
     * The render-resolution canvas size for a chosen orientation — CR80 at
     * 300 DPI, exactly two sizes, matching the `chk_templates_cr80_dimensions`
     * check constraint.
     *
     * @return array{width_px: int, height_px: int}
     */
    public static function dimensionsFor(string $orientation): array
    {
        return $orientation === self::ORIENTATION_PORTRAIT
            ? ['width_px' => self::LANDSCAPE_HEIGHT, 'height_px' => self::LANDSCAPE_WIDTH]
            : ['width_px' => self::LANDSCAPE_WIDTH, 'height_px' => self::LANDSCAPE_HEIGHT];
    }

    /**
     * The template `IssuanceManager` and `CardRenderer` resolve against for
     * a given card type — null is a normal state (a card can be issued or
     * rendered before any template exists for its type; `template_id` is
     * provenance only, rule 13), not an error.
     */
    public static function activeFor(string $idType): ?self
    {
        return self::where('id_type', $idType)->where('is_active', true)->first();
    }

    protected function casts(): array
    {
        return [
            'field_positions_front' => 'array',
            'field_positions_back' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Derived from the stored dimensions, never stored separately — the two
     * can never disagree, because there is only one value.
     */
    public function orientation(): string
    {
        return $this->height_px > $this->width_px ? self::ORIENTATION_PORTRAIT : self::ORIENTATION_LANDSCAPE;
    }

    public function placeableFields(): array
    {
        return self::placeableFieldsFor($this->id_type);
    }

    /**
     * A template is renderable once every placeable front field has a
     * position and both sides carry artwork — the bar `activate()` enforces
     * before allowing `is_active = true`.
     */
    public function isComplete(): bool
    {
        if ($this->overlay_path_front === null || $this->overlay_path_back === null) {
            return false;
        }

        $positions = $this->field_positions_front ?? [];

        foreach ($this->placeableFields() as $field) {
            if (! array_key_exists($field, $positions)) {
                return false;
            }
        }

        return true;
    }

    public function idCards(): HasMany
    {
        return $this->hasMany(IdCard::class);
    }
}
