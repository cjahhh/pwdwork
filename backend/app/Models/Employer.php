<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employer extends Model
{
    public const VERIFICATION_STATUSES = ['pending', 'verified', 'rejected'];

    protected $fillable = [
        'user_id',
        'company_name',
        'company_logo',
        'company_color',
        'website',
        'location',
        'description',
        'verification_status',
        'verification_reason',
        'verification_reviewed_at',
        'verification_requested_at',
        'review_version',
    ];

    protected function casts(): array
    {
        return ['verification_reviewed_at' => 'datetime', 'verification_requested_at' => 'datetime'];
    }

    public function verificationHistory(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class, 'target_id')->where('target_type', 'employer');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobListings(): HasMany
    {
        return $this->hasMany(JobListing::class);
    }
}
