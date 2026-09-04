<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'photo_path',
    'date_of_birth', 'place_of_birth', 'gender',
    'home_address', 'mobile_number', 'landline_number', 'email',
    'emergency_contact_name', 'emergency_contact_number', 'emergency_contact_relation',
    'notes',
])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name} {$this->suffix}");
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
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
