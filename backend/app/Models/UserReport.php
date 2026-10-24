<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserReport extends Model
{
    public const STATUSES = ['pending', 'under_review', 'resolved', 'dismissed'];

    public const CATEGORIES = ['suspicious_job', 'misleading_description', 'inaccurate_accessibility', 'discrimination', 'suspicious_employer', 'account_problem', 'platform_problem'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
