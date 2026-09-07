<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

#[Fillable([
    'occurred_at', 'user_id', 'user_role', 'action',
    'subject_type', 'subject_id', 'previous_value', 'new_value', 'ip_address',
])]
class AuditLog extends Model
{
    use AppendOnly;

    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    // Rows carry `occurred_at`, set explicitly at write time — there is no
    // separate created_at/updated_at pair to manage.
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'previous_value' => 'array',
            'new_value' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The subject, including soft-deleted rows (Phase 4 plan: "uses
     * withTrashed() to resolve deleted subjects"). `subject()` — a plain
     * MorphTo — can't do this itself: the related model's own
     * SoftDeletingScope silently excludes trashed rows, and a deleted
     * subject is exactly the case an audit trail exists to still answer.
     * Returns null if the subject class no longer exists or the row is
     * genuinely gone (audit_logs carries no FK to enforce referential
     * integrity across arbitrary subject types, by design — it must still
     * resolve a person deleted last year, per architecture §3).
     */
    public function subjectWithTrashed(): ?Model
    {
        $class = $this->subject_type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $query = $class::query();

        // Not ->withTrashed(): that method only exists on a Builder whose
        // model is statically known to use SoftDeletes, and $class is a
        // runtime string — Larastan correctly can't verify it here.
        // withTrashed() is itself just sugar for removing this same scope,
        // and withoutGlobalScope() is a real method on the base Builder
        // regardless of the concrete model, so this is the identical
        // runtime effect through a call that type-checks honestly rather
        // than one that happens to work and can't be verified.
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query = $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->find($this->subject_id);
    }

    /**
     * A human-readable "Type #id (label)" string for the audit viewer.
     * Tries the identifying attribute each subject type is actually
     * expected to have — a bare "#3" tells nobody what changed.
     */
    public function subjectLabel(): string
    {
        $model = $this->subjectWithTrashed();
        $type = class_basename($this->subject_type);

        if ($model === null) {
            return "{$type} #{$this->subject_id}";
        }

        $label = $model->username
            ?? $model->name
            ?? (isset($model->first_name) ? trim($model->first_name.' '.($model->last_name ?? '')) : null)
            ?? (string) $model->getKey();

        return "{$type} #{$this->subject_id} ({$label})";
    }
}
