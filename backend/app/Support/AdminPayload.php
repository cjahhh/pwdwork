<?php

namespace App\Support;

use App\Models\AdminAuditLog;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserReport;

class AdminPayload
{
    public static function user(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role,
            'status' => $user->status, 'createdAt' => $user->created_at?->toIso8601String(), 'reviewVersion' => $user->admin_version];
    }

    public static function employer(Employer $employer): array
    {
        return ['id' => $employer->id, 'companyName' => $employer->company_name,
            'description' => $employer->description ?? '', 'location' => $employer->location ?? '', 'website' => $employer->website ?? '',
            'contactName' => $employer->user->name, 'contactEmail' => $employer->user->email, 'accountId' => $employer->user_id,
            'accountStatus' => $employer->user->status, 'verificationStatus' => $employer->verification_status,
            'verificationReason' => $employer->verification_reason, 'reviewVersion' => $employer->review_version,
            'createdAt' => $employer->created_at?->toIso8601String(), 'requestedAt' => $employer->verification_requested_at?->toIso8601String(),
            'reviewedAt' => $employer->verification_reviewed_at?->toIso8601String()];
    }

    public static function job(JobListing $job): array
    {
        return ['id' => $job->id, 'title' => $job->title, 'description' => $job->description,
            'location' => $job->location, 'employmentType' => $job->employment_type, 'workMode' => $job->work_mode,
            'flexibleSchedule' => $job->flexible_schedule, 'availability' => $job->status,
            'moderationStatus' => $job->moderation_status, 'moderationReason' => $job->moderation_reason,
            'reviewVersion' => $job->review_version, 'createdAt' => $job->created_at?->toIso8601String(),
            'accommodations' => $job->accommodations->pluck('label')->values(), 'employer' => self::employer($job->employer)];
    }

    public static function report(UserReport $report, bool $admin = false): array
    {
        $result = ['id' => $report->id, 'targetType' => $report->target_type, 'targetId' => $report->target_id,
            'category' => $report->category, 'description' => $report->description, 'status' => $report->status,
            'createdAt' => $report->created_at?->toIso8601String(), 'reviewedAt' => $report->reviewed_at?->toIso8601String()];
        if ($admin) {
            $result += ['reporter' => self::user($report->reporter), 'reviewer' => $report->reviewer?->name,
                'internalNotes' => $report->internal_notes ?? '', 'reviewVersion' => $report->review_version];
        }

        return $result;
    }

    public static function audit(AdminAuditLog $log): array
    {
        return ['id' => $log->id, 'actorId' => $log->actor_id, 'actorName' => $log->actor?->name ?? ($log->actor_role === 'cli' ? 'Authorized CLI' : 'Former account'),
            'actorRole' => $log->actor_role, 'action' => $log->action, 'targetType' => $log->target_type, 'targetId' => $log->target_id,
            'beforeStatus' => $log->before_status, 'afterStatus' => $log->after_status,
            'reason' => $log->reason, 'createdAt' => $log->created_at?->toIso8601String()];
    }
}
