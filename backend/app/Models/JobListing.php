<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobListing extends Model
{
    public const EMPLOYMENT_TYPES = ['Full Time', 'Part Time', 'Contract'];

    protected $fillable = [
        'employer_id',
        'title',
        'location',
        'employment_type',
        'work_mode',
        'description',
        'status',
        'flexible_schedule',
    ];

    protected function casts(): array
    {
        return ['flexible_schedule' => 'boolean'];
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
