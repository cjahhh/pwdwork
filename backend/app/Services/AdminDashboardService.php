<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Application;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserReport;

class AdminDashboardService
{
    public function statistics(): array
    {
        // COUNT, rather than SUM, preserves integer zero for empty tables.
        $users = User::query()->selectRaw(
            'COUNT(CASE WHEN role = ? THEN 1 END) AS jobseekers, COUNT(CASE WHEN role = ? THEN 1 END) AS employers, COUNT(CASE WHEN status = ? THEN 1 END) AS suspended',
            ['applicant', 'employer', 'suspended']
        )->toBase()->first();
        $employers = Employer::query()->selectRaw(
            'COUNT(CASE WHEN verification_status = ? THEN 1 END) AS verified, COUNT(CASE WHEN verification_status = ? THEN 1 END) AS pending, COUNT(CASE WHEN verification_status = ? THEN 1 END) AS rejected',
            ['verified', 'pending', 'rejected']
        )->toBase()->first();
        $jobs = JobListing::query()->selectRaw(
            'COUNT(*) AS total, COUNT(CASE WHEN moderation_status = ? THEN 1 END) AS pending', ['pending']
        )->toBase()->first();

        return [
            'jobseekers' => (int) $users->jobseekers, 'employers' => (int) $users->employers,
            'verifiedEmployers' => (int) $employers->verified, 'pendingEmployers' => (int) $employers->pending,
            'rejectedEmployers' => (int) $employers->rejected,
            'jobListings' => (int) $jobs->total,
            // Keep the complete publication, employer verification and account scope.
            'activeApprovedJobs' => JobListing::publiclyVisible()->count(),
            'pendingJobs' => (int) $jobs->pending,
            'applications' => Application::count(), 'pendingReports' => UserReport::where('status', 'pending')->count(),
            'suspendedAccounts' => (int) $users->suspended,
        ];
    }

    /** Fixed first-page card fields only; full records and pagination stay on the existing endpoints. */
    public function dashboard(): array
    {
        return [
            'stats' => $this->statistics(),
            'employers' => Employer::where('verification_status', 'pending')->latest()->orderByDesc('id')->limit(3)
                ->get(['id', 'company_name'])->map(fn ($employer) => ['id' => $employer->id, 'companyName' => $employer->company_name])->values(),
            'jobs' => JobListing::where('moderation_status', 'pending')->latest()->orderByDesc('id')->limit(3)
                ->get(['id', 'title'])->map(fn ($job) => ['id' => $job->id, 'title' => $job->title])->values(),
            'reports' => UserReport::where('status', 'pending')->latest()->orderByDesc('id')->limit(3)
                ->get(['id', 'category'])->map(fn ($report) => ['id' => $report->id, 'category' => $report->category])->values(),
            'audit' => AdminAuditLog::orderByDesc('id')->limit(3)->get(['id', 'action', 'target_type', 'target_id'])
                ->map(fn ($log) => ['id' => $log->id, 'action' => $log->action, 'targetType' => $log->target_type, 'targetId' => $log->target_id])->values(),
        ];
    }
}
