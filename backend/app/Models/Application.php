<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Application extends Model
{
    public const STATUSES = ['submitted', 'reviewing', 'shortlisted', 'interview', 'hired', 'rejected'];

    protected $fillable = [
        'user_id',
        'job_listing_id',
        'cover_letter',
        'status',
        'interview_date',
        'interview_time',
        'interview_notes',
        'share_accommodations',
    ];

    protected function casts(): array
    {
        return ['interview_date' => 'date', 'share_accommodations' => 'boolean'];
    }

    public function statusLabel(bool $employer = false): string
    {
        return match ($this->status) {
            'submitted' => $employer ? 'New' : 'Submitted',
            'reviewing' => 'Reviewing',
            'shortlisted' => 'Shortlisted',
            'interview' => 'Interview',
            'hired' => 'Hired',
            'rejected' => 'Rejected',
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }
}
