<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Database\Factories\SecurityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['occurred_at', 'user_id', 'event_type', 'detail', 'ip_address'])]
class SecurityEvent extends Model
{
    use AppendOnly;

    /** @use HasFactory<SecurityEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'detail' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
