<?php

namespace App\Models;

use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'id_type', 'name', 'background_path_front', 'background_path_back',
    'width_px', 'height_px', 'field_positions_front', 'field_positions_back', 'is_active',
])]
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'field_positions_front' => 'array',
            'field_positions_back' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function idCards(): HasMany
    {
        return $this->hasMany(IdCard::class);
    }
}
