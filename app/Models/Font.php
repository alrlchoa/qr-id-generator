<?php

namespace App\Models;

use Database\Factories\FontFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A Superadmin-uploaded TrueType font for card rendering — closes the "no
 * font bundled" gap `CardRenderer` shipped with in Phase 12. At most one
 * is `is_active` at a time (`uq_fonts_active`), which is what
 * `activeFont()` resolves and what `CardRenderer` actually draws text
 * with; `null` here is a normal state, not an error — `CardRenderer`
 * falls back to GD's five built-in bitmap sizes when no font is active.
 */
#[Fillable(['name', 'original_filename', 'storage_path', 'is_active'])]
class Font extends Model
{
    /** @use HasFactory<FontFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function activeFont(): ?self
    {
        return self::where('is_active', true)->first();
    }
}
