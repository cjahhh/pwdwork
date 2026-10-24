<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobListing extends Model
{
    public const EMPLOYMENT_TYPES = ['Full Time', 'Part Time', 'Contract'];

    public const MODERATION_STATUSES = ['pending', 'approved', 'rejected', 'hidden'];

    protected $fillable = [
        'employer_id',
        'title',
        'location',
        'employment_type',
        'work_mode',
        'description',
        'status',
        'flexible_schedule',
        'moderation_status',
        'moderation_reason',
        'moderation_reviewed_at',
        'review_version',
    ];

    protected function casts(): array
    {
        return ['flexible_schedule' => 'boolean', 'moderation_reviewed_at' => 'datetime'];
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('job_listings.status', 'open')->where('moderation_status', 'approved')
            ->whereHas('employer', fn ($employer) => $employer->where('verification_status', 'verified')
                ->whereHas('user', fn ($user) => $user->where('status', 'active')->where('role', 'employer')));
    }

    public function moderationHistory(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'target_id')->where('target_type', 'job');
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->status === 'open' && $this->moderation_status === 'approved'
            && $this->employer?->verification_status === 'verified'
            && $this->employer?->user?->status === 'active' && $this->employer?->user?->role === 'employer';
    }

    public function employer(): BelongsTo
    {
        return $this->belongsTo(Employer::class);
    }

    public function accommodations(): BelongsToMany
    {
        return $this->belongsToMany(Accommodation::class, 'job_listing_accommodation')->withTimestamps();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function savedByUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_jobs')->withTimestamps();
    }
}
