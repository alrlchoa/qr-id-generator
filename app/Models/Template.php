<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['id_type', 'name', 'background_path', 'width_px', 'height_px', 'field_positions', 'is_active'])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'field_positions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function idCards(): HasMany
    {
        return $this->hasMany(IdCard::class);
    }
}
