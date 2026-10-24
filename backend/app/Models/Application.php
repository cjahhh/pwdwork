<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

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

    public function accommodationRequests(): HasMany
    {
        return $this->hasMany(AccommodationRequest::class);
    }

    public function confirmedAccommodationRequests(): HasMany
    {
        return $this->accommodationRequests()->where('status', 'agreed')->whereNull('withdrawn_at')->where('stage', 'interview');
    }

    public static function confirmedRequestRelations(): array
    {
        // Preserve existing application APIs while the additive Phase 4 upgrade is pending.
        return Schema::hasTable('accommodation_requests') ? ['confirmedAccommodationRequests'] : [];
    }

    public function confirmedArrangements(): array
    {
        if (! $this->relationLoaded('confirmedAccommodationRequests')) {
            return [];
        }
        $interview = ['date' => $this->interview_date?->toDateString(), 'time' => $this->interview_time ? substr($this->interview_time, 0, 5) : null];

        return $this->confirmedAccommodationRequests->map(fn ($record) => [
            'id' => $record->id, 'arrangement' => $record->arrangement, 'confirmedInterview' => $record->arrangement_interview,
            'scheduleChanged' => $record->arrangement_interview !== $interview,
        ])->all();
    }

    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }
}
