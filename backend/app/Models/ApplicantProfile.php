<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ApplicantProfile extends Model
{
    protected $fillable = [
        'user_id',
        'headline',
        'skills',
        'phone',
        'location',
        'preferred_work_mode',
        'summary',
        'resume_path',
        'pwd_id_number',
    ];

    protected function casts(): array
    {
        return ['skills' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accommodations(): BelongsToMany
    {
        return $this->belongsToMany(Accommodation::class, 'applicant_accommodation')->withTimestamps();
    }
}
