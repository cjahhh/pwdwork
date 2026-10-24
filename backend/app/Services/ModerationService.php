<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserReport;
use App\Notifications\ModerationDecision;
use Illuminate\Support\Facades\DB;

class ModerationService
{
    public static function expectVersion(int $actual, int $expected): void
    {
        abort_if($actual !== $expected, 409, 'This record changed. Close this review, refresh, and review the latest details before deciding.');
    }

    private function lockAdmin(User $actor): User
    {
        // A common lock order also protects the last-admin check under concurrent restrictions.
        $admins = User::where('role', 'admin')->orderBy('id')->lockForUpdate()->get();
        $admin = $admins->firstWhere('id', $actor->id);
        abort_unless($admin && $admin->status === 'active', 403, 'An active administrator is required.');

        return $admin;
    }

    public function notify(User $user, string $title, string $body, string $path): void
    {
        UserNotification::create(['user_id' => $user->id, 'title' => $title, 'body' => $body, 'action_url' => $path]);
        if (config('administration.email_notifications')) {
            DB::afterCommit(function () use ($user, $title, $body, $path) {
                try {
                    $user->notify(new ModerationDecision($title, $body, $path));
                } catch (\Throwable $exception) {
                    // The durable in-app notice remains available if the optional mail queue is down.
                    report($exception);
                }
            });
        }
    }

    public function verifyEmployer(User $actor, Employer $target, array $data): Employer
    {
        return DB::transaction(function () use ($actor, $target, $data) {
            $actor = $this->lockAdmin($actor);
            $owner = User::whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $employer = Employer::whereKey($target->id)->lockForUpdate()->firstOrFail();
            self::expectVersion($employer->review_version, $data['expected_version']);
            abort_if($data['status'] === $employer->verification_status, 409, 'This employer already has that verification status.');
            abort_if($data['status'] === 'verified' && ($owner->status !== 'active' || ! $owner->isEmployer()), 422, 'Only an active employer account can be verified.');
            $before = $employer->verification_status;
            $employer->update(['verification_status' => $data['status'], 'verification_reason' => $data['reason'] ?? null,
                'verification_reviewed_at' => now(), 'review_version' => $employer->review_version + 1]);
            AdminAuditLog::record($actor, 'employer.'.$data['status'], 'employer', $employer->id, $before, $data['status'], $data['reason'] ?? null);
            $this->notify($owner, 'Employer verification '.$data['status'], 'Your company verification is '.$data['status'].'. '.($data['reason'] ?? ''), '/employer/profile');

            return $employer->load('user');
        }, 3);
    }

    public function moderateJob(User $actor, JobListing $target, array $data): JobListing
    {
        return DB::transaction(function () use ($actor, $target, $data) {
            $actor = $this->lockAdmin($actor);
            $ownerId = $target->employer->user_id;
            $owner = User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
            $employer = Employer::whereKey($target->employer_id)->lockForUpdate()->firstOrFail();
            $job = JobListing::whereKey($target->id)->lockForUpdate()->firstOrFail();
            self::expectVersion($job->review_version, $data['expected_version']);
            abort_if($job->moderation_status === $data['status'], 409, 'This listing already has that moderation status.');
            abort_if($data['status'] === 'approved' && ($employer->verification_status !== 'verified' || $owner->status !== 'active' || ! $owner->isEmployer()), 422, 'Approval requires a verified, active employer.');
            $before = $job->moderation_status;
            $job->update(['moderation_status' => $data['status'], 'moderation_reason' => $data['reason'] ?? null,
                'moderation_reviewed_at' => now(), 'review_version' => $job->review_version + 1]);
            AdminAuditLog::record($actor, 'job.'.$data['status'], 'job', $job->id, $before, $data['status'], $data['reason'] ?? null);
            $this->notify($owner, 'Listing '.$data['status'], 'Your listing "'.$job->title.'" is '.$data['status'].'. '.($data['reason'] ?? ''), '/employer/listings');

            return $job->load(['employer.user', 'accommodations']);
        }, 3);
    }

    public function updateUser(User $actor, User $target, array $data): User
    {
        return DB::transaction(function () use ($actor, $target, $data) {
            $actor = $this->lockAdmin($actor);
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            self::expectVersion($user->admin_version, $data['expected_version']);
            abort_if($user->status === $data['status'], 409, 'This account already has that status.');
            if ($data['status'] === 'suspended') {
                abort_if($user->id === $actor->id, 422, 'You cannot suspend your own account.');
                abort_if($user->isAdmin() && User::where('role', 'admin')->where('status', 'active')->count() <= 1, 422, 'At least one active administrator must remain.');
            }
            $before = $user->status;
            $user->forceFill(['status' => $data['status'], 'admin_version' => $user->admin_version + 1])->save();
            if ($data['status'] === 'suspended') {
                $user->tokens()->delete();
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }
            }
            AdminAuditLog::record($actor, 'user.'.$data['status'], 'user', $user->id, $before, $data['status'], $data['reason']);
            $this->notify($user, 'Account '.$data['status'], 'Your account is '.$data['status'].'. '.$data['reason'], '/notifications');

            return $user;
        }, 3);
    }

    public function updateReport(User $actor, UserReport $target, array $data): UserReport
    {
        return DB::transaction(function () use ($actor, $target, $data) {
            $actor = $this->lockAdmin($actor);
            $report = UserReport::whereKey($target->id)->lockForUpdate()->firstOrFail();
            self::expectVersion($report->review_version, $data['expected_version']);
            abort_if(in_array($report->status, ['resolved', 'dismissed'], true) && $data['status'] !== $report->status, 422, 'A completed report cannot be reopened. Submit a new report if a problem recurs.');
            $before = $report->status;
            $report->update(['status' => $data['status'], 'internal_notes' => array_key_exists('internal_notes', $data) ? $data['internal_notes'] : $report->internal_notes,
                'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_version' => $report->review_version + 1,
                'open_fingerprint' => in_array($data['status'], ['resolved', 'dismissed'], true) ? null : $report->open_fingerprint]);
            AdminAuditLog::record($actor, 'report.'.$data['status'], 'report', $report->id, $before, $data['status'], $data['reason'] ?? null);
            $this->notify($report->reporter, 'Report #'.$report->id.' updated', 'Your report is now '.str_replace('_', ' ', $data['status']).'.', '/reports');

            return $report->load(['reporter', 'reviewer']);
        }, 3);
    }

    public function requestEmployerReview(User $user, int $version): Employer
    {
        return DB::transaction(function () use ($user, $version) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->status === 'active', 403);
            $employer = $user->employer()->lockForUpdate()->firstOrFail();
            self::expectVersion($employer->review_version, $version);
            abort_unless($employer->verification_status === 'rejected', 422, 'Only a rejected verification needs resubmission. Pending requests are already in the review queue.');
            $employer->update(['verification_status' => 'pending', 'verification_reason' => null, 'verification_requested_at' => now(), 'review_version' => $employer->review_version + 1]);
            AdminAuditLog::record($user, 'employer.review_requested', 'employer', $employer->id, 'rejected', 'pending', 'Employer requested another review.');

            return $employer;
        }, 3);
    }

    public function submitJob(User $user, JobListing $target, int $version): JobListing
    {
        return DB::transaction(function () use ($user, $target, $version) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $employer = $user->employer()->lockForUpdate()->firstOrFail();
            $job = JobListing::whereKey($target->id)->lockForUpdate()->firstOrFail();
            abort_unless($job->employer_id === $employer->id, 403);
            self::expectVersion($job->review_version, $version);
            abort_unless($user->status === 'active' && $employer->verification_status === 'verified', 422, 'Your employer account must be verified and active before submitting a listing.');
            abort_if($job->moderation_status === 'hidden', 422, 'This listing is hidden by an administrator. Contact support through a report for another review.');
            abort_if($job->moderation_status === 'approved', 422, 'This listing is already approved. Use the availability controls to reopen it.');
            abort_if($job->moderation_status === 'pending' && $job->status === 'open', 409, 'This listing is already awaiting review.');
            $before = $job->moderation_status;
            $job->update(['status' => 'open', 'moderation_status' => 'pending', 'moderation_reason' => null, 'review_version' => $job->review_version + 1]);
            AdminAuditLog::record($user, 'job.review_requested', 'job', $job->id, $before, 'pending', 'Employer submitted the listing for review.');

            return $job->load(['employer', 'accommodations']);
        }, 3);
    }
}
