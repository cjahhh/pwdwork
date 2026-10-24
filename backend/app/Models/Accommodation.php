<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Accommodation extends Model
{
    public const STANDARD_LABELS = [
        'Step-free or wheelchair-accessible entrance', 'Accessible restroom',
        'Assistive technology support', 'Sign-language interpretation',
        'Accessible interview options', 'Adjustable workspace',
    ];

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
