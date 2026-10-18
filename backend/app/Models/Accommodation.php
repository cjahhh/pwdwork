<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Accommodation extends Model
{
    protected $fillable = [
        'slug',
        'label',
        'icon',
    ];

    public function jobListings(): BelongsToMany
    {
        return $this->belongsToMany(JobListing::class, 'job_listing_accommodation')->withTimestamps();
    }

    public function applicantProfiles(): BelongsToMany
    {
        return $this->belongsToMany(ApplicantProfile::class, 'applicant_accommodation')->withTimestamps();
    }
}
