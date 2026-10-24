<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(?User $actor, string $action, string $type, int $id, ?string $before, ?string $after, ?string $reason = null): self
    {
        return self::create(['actor_id' => $actor?->id, 'actor_role' => $actor?->role ?? 'cli', 'action' => $action,
            'target_type' => $type, 'target_id' => $id, 'before_status' => $before, 'after_status' => $after,
            'reason' => $reason, 'created_at' => now()]);
    }
}
