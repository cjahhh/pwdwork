<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccommodationRequest extends Model
{
    public const STATUSES = ['submitted', 'under_discussion', 'agreed', 'alternative_proposed', 'unable_to_provide', 'withdrawn'];

    public const STAGES = ['interview', 'assessment', 'recruitment'];

    public const EXTRA_CATEGORIES = ['Alternative interview format', 'Additional assessment time', 'Accessible communication format', 'Flexible interview scheduling', 'Accessible online meeting platform', 'Other practical arrangement'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['categories' => 'array', 'interview_snapshot' => 'array', 'arrangement_interview' => 'array', 'shared_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    public static function categories(): array
    {
        return [...Accommodation::STANDARD_LABELS, ...self::EXTRA_CATEGORIES];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AccommodationRequestMessage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccommodationRequestEvent::class);
    }

    public function scopeForParticipant(Builder $query, User $user): Builder
    {
        if ($user->role === 'applicant') {
            return $query->whereHas('application', fn ($application) => $application->where('user_id', $user->id));
        }
        if ($user->role === 'employer') {
            return $query->whereNull('withdrawn_at')->whereHas('application.jobListing.employer', fn ($employer) => $employer->where('user_id', $user->id));
        }

        return $query->whereRaw('1 = 0');
    }
}
