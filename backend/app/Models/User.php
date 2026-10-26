<?php

namespace App\Models;

use App\Services\EmailVerification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_ownership_verified_at' => 'datetime',
            'email_verification_required_at' => 'datetime',
            'email_verification_version' => 'integer',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->isDirty('email') && array_key_exists('email_ownership_verified_at', $user->getAttributes())) {
                $required = $user->email_verification_required_at;
                if ($user->hasVerifiedEmail() || $required !== null) {
                    $required ??= now();
                }
                $user->forceFill([
                    'email_verified_at' => null,
                    'email_ownership_verified_at' => null,
                    'email_verification_required_at' => $required,
                    'email_verification_version' => (int) $user->email_verification_version + 1,
                ]);
            }
        });
    }

    public function hasVerifiedEmail(): bool
    {
        // Historical email_verified_at values are not evidence of ownership.
        return $this->email_ownership_verified_at !== null;
    }

    public function requiresEmailVerification(): bool
    {
        return $this->email_verification_required_at !== null && ! $this->hasVerifiedEmail();
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
            'email_ownership_verified_at' => $this->freshTimestamp(),
            'email_verification_version' => (int) $this->email_verification_version + 1,
        ])->save();
    }

    public function markEmailAsUnverified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => null,
            'email_ownership_verified_at' => null,
            'email_verification_required_at' => $this->email_verification_required_at ?? now(),
            'email_verification_version' => (int) $this->email_verification_version + 1,
        ])->save();
    }

    public function sendEmailVerificationNotification(): void
    {
        app(EmailVerification::class)->request($this);
    }

    public function applicantProfile(): HasOne
    {
        return $this->hasOne(ApplicantProfile::class);
    }

    public function employer(): HasOne
    {
        return $this->hasOne(Employer::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function savedJobs(): BelongsToMany
    {
        return $this->belongsToMany(JobListing::class, 'saved_jobs')->withTimestamps();
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(UserReport::class, 'reporter_id');
    }

    public function isApplicant(): bool
    {
        return $this->role === 'applicant';
    }

    public function isEmployer(): bool
    {
        return $this->role === 'employer';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
