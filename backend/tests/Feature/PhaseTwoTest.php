<?php

namespace Tests\Feature;

use App\Models\AdminAuditLog;
use App\Models\Application;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserReport;
use App\Notifications\ModerationDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseTwoTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'applicant', array $fields = []): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', ...$fields])->fresh();
    }

    private function company(string $status = 'pending'): Employer
    {
        return Employer::create(['user_id' => $this->account('employer')->id, 'company_name' => 'Review Company',
            'description' => 'A real company profile', 'location' => 'Manila', 'website' => 'https://company.example', 'verification_status' => $status])->fresh();
    }

    private function job(?Employer $company = null, array $fields = []): JobListing
    {
        $company ??= $this->company('verified');

        return $company->jobListings()->create(['title' => 'Support opportunity', 'location' => 'Manila', 'employment_type' => 'Contract',
            'work_mode' => 'Remote', 'description' => 'Professional support role.', 'status' => 'open', ...$fields])->fresh();
    }

    private function asAdmin(): User
    {
        $admin = $this->account('admin');
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function decide(string $type, $record, string $status, array $extra = [])
    {
        $suffix = ['employers' => '/verification', 'jobs' => '/moderation', 'users' => '/status', 'reports' => ''][$type];

        return $this->patchJson('/api/admin/'.$type.'/'.$record->id.$suffix,
            ['status' => $status, 'expected_version' => $type === 'users' ? $record->fresh()->admin_version : $record->fresh()->review_version,
                'reason' => 'Reviewed with an appropriate explanation.', ...$extra]);
    }

    public function test_admin_authorization_is_enforced_on_reads_writes_and_audit(): void
    {
        $company = $this->company();
        $job = $this->job($company);
        $user = $this->account();
        $reads = ['/api/admin/overview', '/api/admin/employers', '/api/admin/employers/'.$company->id,
            '/api/admin/jobs', '/api/admin/jobs/'.$job->id, '/api/admin/users', '/api/admin/users/'.$user->id,
            '/api/admin/reports', '/api/admin/audit-logs'];
        foreach ($reads as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        foreach ([$user, $company->user] as $ordinary) {
            Sanctum::actingAs($ordinary);
            foreach ($reads as $url) {
                $this->getJson($url)->assertForbidden();
            }
            $this->decide('employers', $company, 'verified')->assertForbidden();
            $this->decide('jobs', $job, 'approved')->assertForbidden();
            $this->decide('users', $user, 'suspended')->assertForbidden();
        }
        $this->asAdmin();
        foreach ($reads as $url) {
            $this->getJson($url)->assertOk();
        }
        $this->patchJson('/api/admin/audit-logs/1', [])->assertNotFound();
    }

    public function test_admin_uses_normal_cookie_login_and_suspended_admins_are_blocked(): void
    {
        $admin = $this->account('admin');
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertOk()->assertJsonPath('user.role', 'admin');
        $this->getJson('/api/admin/overview')->assertOk();
        $admin->update(['status' => 'suspended']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/overview')->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->assertForbidden();
    }

    public function test_registration_is_pending_and_rejected_employers_can_correct_and_resubmit(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/register', ['name' => 'Company Owner', 'email' => 'owner@phase2.example', 'company_name' => 'New Company',
            'password' => 'password', 'role' => 'employer', 'verification_status' => 'verified'])->assertCreated();
        $company = Employer::firstOrFail();
        $this->assertSame('pending', $company->verification_status);
        $this->flushHeaders();
        $admin = $this->asAdmin();
        $this->decide('employers', $company, 'rejected', ['reason' => null])->assertUnprocessable();
        $this->decide('employers', $company, 'rejected', ['reason' => 'Please clarify the company address.'])->assertOk();
        $this->assertDatabaseHas('admin_audit_logs', ['actor_id' => $admin->id, 'action' => 'employer.rejected', 'target_id' => $company->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $company->user_id, 'title' => 'Employer verification rejected']);
        Sanctum::actingAs($company->user);
        $this->getJson('/api/profile')->assertJsonPath('profile.verificationReason', 'Please clarify the company address.');
        $this->putJson('/api/profile', ['companyName' => 'Corrected Company', 'location' => 'Quezon City', 'verificationStatus' => 'verified'])
            ->assertOk()->assertJsonPath('profile.verificationStatus', 'rejected');
        $this->postJson('/api/employer/verification-request', ['expected_version' => $company->fresh()->review_version])
            ->assertOk();
        $this->assertSame('pending', $company->fresh()->verification_status);
        Sanctum::actingAs($admin);
        $this->decide('employers', $company, 'verified')->assertOk();
        Sanctum::actingAs($company->user);
        $this->getJson('/api/employer/verification-history?per_page=2')->assertOk()->assertJsonPath('meta.total', 5);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_company_edits_invalidate_verification_and_stale_reviews_cannot_overwrite_them(): void
    {
        $company = $this->company('verified');
        $job = $this->job($company, ['moderation_status' => 'approved']);
        $version = $company->review_version;
        Sanctum::actingAs($company->user);
        $this->putJson('/api/profile', ['companyName' => 'Changed Company', 'website' => '', 'location' => 'Cebu'])
            ->assertOk()->assertJsonPath('profile.verificationStatus', 'pending')->assertJsonPath('profile.website', '');
        $this->getJson('/api/jobs')->assertJsonPath('meta.total', 0);
        $this->asAdmin();
        $this->decide('employers', $company, 'verified', ['expected_version' => $version])->assertConflict();
        $this->assertSame('pending', $company->fresh()->verification_status);
        $this->decide('employers', $company, 'verified')->assertOk();
        $this->getJson('/api/jobs')->assertJsonPath('jobs.0.id', $job->id);
    }

    public function test_unverified_employers_can_save_but_cannot_publish_or_bypass_approval(): void
    {
        $company = $this->company();
        Sanctum::actingAs($company->user);
        $created = $this->postJson('/api/employer/jobs', ['title' => 'New opportunity', 'location' => 'Cebu', 'employment_type' => 'Contract',
            'work_mode' => 'Hybrid', 'description' => 'An accessible role.', 'moderation_status' => 'approved', 'status' => 'open'])
            ->assertCreated()->assertJsonPath('job.status', 'Closed')->assertJsonPath('job.moderationStatus', 'pending');
        $job = JobListing::findOrFail($created->json('job.id'));
        $this->postJson('/api/employer/jobs/'.$job->id.'/submit', ['expected_version' => $job->review_version])->assertUnprocessable();
        $this->patchJson('/api/employer/jobs/'.$job->id, ['status' => 'open', 'moderation_status' => 'approved'])->assertUnprocessable();
        $this->getJson('/api/jobs/'.$job->id)->assertNotFound();
        $this->asAdmin();
        $this->decide('jobs', $job, 'approved')->assertUnprocessable();
        $this->decide('employers', $company, 'verified')->assertOk();
        Sanctum::actingAs($company->user);
        $this->postJson('/api/employer/jobs/'.$job->id.'/submit', ['expected_version' => $job->fresh()->review_version])->assertOk();
        $this->getJson('/api/jobs')->assertJsonPath('meta.total', 0);
        $this->asAdmin();
        $this->decide('jobs', $job, 'approved')->assertOk();
        $this->getJson('/api/jobs?types[]=Contract')->assertJsonPath('jobs.0.id', $job->id);
    }

    public function test_job_approval_edits_rejection_resubmission_and_hidden_protection(): void
    {
        $company = $this->company('verified');
        $job = $this->job($company);
        Sanctum::actingAs($this->account());
        $this->getJson('/api/jobs/'.$job->id)->assertNotFound();
        $this->postJson('/api/jobs/'.$job->id.'/applications')->assertUnprocessable();
        $this->putJson('/api/applicant/saved-jobs/'.$job->id)->assertNotFound();
        $admin = $this->asAdmin();
        $this->decide('jobs', $job, 'approved')->assertOk();
        $this->getJson('/api/jobs/'.$job->id)->assertOk();
        $oldVersion = $job->fresh()->review_version;
        Sanctum::actingAs($company->user);
        $this->patchJson('/api/employer/jobs/'.$job->id, ['description' => 'Changed description.', 'moderation_status' => 'approved'])
            ->assertOk()->assertJsonPath('job.moderationStatus', 'pending');
        $this->getJson('/api/jobs/'.$job->id)->assertNotFound();
        Sanctum::actingAs($admin);
        $this->decide('jobs', $job, 'approved', ['expected_version' => $oldVersion])->assertConflict();
        $this->decide('jobs', $job, 'rejected', ['reason' => null])->assertUnprocessable();
        $this->decide('jobs', $job, 'rejected')->assertOk();
        Sanctum::actingAs($company->user);
        $this->patchJson('/api/employer/jobs/'.$job->id, ['description' => 'Corrected role requirements.'])->assertOk()->assertJsonPath('job.moderationStatus', 'rejected');
        $this->postJson('/api/employer/jobs/'.$job->id.'/submit', ['expected_version' => $job->fresh()->review_version])->assertOk();
        Sanctum::actingAs($admin);
        $this->decide('jobs', $job, 'hidden')->assertOk();
        Sanctum::actingAs($company->user);
        $this->patchJson('/api/employer/jobs/'.$job->id, ['title' => 'Renamed role'])->assertOk()->assertJsonPath('job.moderationStatus', 'hidden');
        $this->postJson('/api/employer/jobs/'.$job->id.'/submit', ['expected_version' => $job->fresh()->review_version])->assertUnprocessable();
        $this->getJson('/api/employer/jobs/'.$job->id.'/history')->assertJsonPath('meta.total', 7);
    }

    public function test_visibility_requires_all_publication_conditions_and_saved_jobs_do_not_leak_pending_content(): void
    {
        $company = $this->company('verified');
        $job = $this->job($company, ['moderation_status' => 'approved']);
        $candidate = $this->account();
        $candidate->savedJobs()->attach($job);
        Sanctum::actingAs($candidate);
        $this->postJson('/api/jobs/'.$job->id.'/applications')->assertCreated();
        foreach ([['pending', 'open', 'verified', 'active'], ['rejected', 'open', 'verified', 'active'], ['hidden', 'open', 'verified', 'active'],
            ['approved', 'closed', 'verified', 'active'], ['approved', 'open', 'pending', 'active'], ['approved', 'open', 'rejected', 'active'],
            ['approved', 'open', 'verified', 'suspended']] as [$moderation, $availability, $verification, $status]) {
            $job->update(['moderation_status' => $moderation, 'status' => $availability]);
            $company->update(['verification_status' => $verification]);
            $company->user->update(['status' => $status]);
            $this->getJson('/api/jobs')->assertJsonPath('meta.total', 0);
            $this->getJson('/api/jobs/'.$job->id)->assertNotFound();
            $this->postJson('/api/jobs/'.$job->id.'/applications')->assertUnprocessable();
            $saved = $this->getJson('/api/applicant/saved-jobs')->assertJsonPath('jobs.0.title', 'Unavailable listing');
            $this->assertStringNotContainsString('Professional support role.', $saved->getContent());
        }
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('saved_jobs', 1);
    }

    public function test_suspension_revokes_credentials_preserves_records_and_protects_admins(): void
    {
        config(['session.driver' => 'database']);
        $company = $this->company('verified');
        $job = $this->job($company, ['moderation_status' => 'approved']);
        $candidate = $this->account();
        $candidate->savedJobs()->attach($job);
        $candidate->createToken('existing');
        DB::table('sessions')->insert(['id' => 'candidate-session', 'user_id' => $candidate->id, 'payload' => '', 'last_activity' => time()]);
        $admin = $this->asAdmin();
        $this->decide('users', $candidate, 'suspended', ['reason' => ''])->assertUnprocessable();
        $this->decide('users', $candidate, 'suspended')->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $candidate->id]);
        $this->assertSame(0, $candidate->tokens()->count());
        $this->assertDatabaseCount('saved_jobs', 1);
        Sanctum::actingAs($candidate->fresh());
        $this->getJson('/api/profile')->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->postJson('/api/jobs/'.$job->id.'/applications')->assertForbidden();
        Sanctum::actingAs($admin);
        $this->decide('users', $admin, 'suspended')->assertUnprocessable();
        $second = $this->account('admin');
        $this->decide('users', $second, 'suspended')->assertOk();
        $this->assertSame(1, User::where('role', 'admin')->where('status', 'active')->count());
        $this->decide('users', $candidate, 'active')->assertOk();
        Sanctum::actingAs($candidate->fresh());
        $this->getJson('/api/profile')->assertOk();
        $this->assertSame(0, $candidate->tokens()->count());
    }

    public function test_reports_are_private_deduplicated_reviewable_and_do_not_expose_internal_notes(): void
    {
        $company = $this->company('verified');
        $job = $this->job($company, ['moderation_status' => 'approved']);
        $reporter = $this->account();
        Sanctum::actingAs($reporter);
        $payload = ['target_type' => 'job', 'target_id' => $job->id, 'category' => 'inaccurate_accessibility', 'description' => 'The accessible restroom information needs review.'];
        $this->postJson('/api/reports', $payload)->assertCreated()->assertJsonPath('report.status', 'pending');
        $this->postJson('/api/reports', $payload)->assertConflict();
        $report = UserReport::firstOrFail();
        Sanctum::actingAs($this->account());
        $this->getJson('/api/reports')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/admin/reports/'.$report->id)->assertForbidden();
        $admin = $this->asAdmin();
        $this->decide('reports', $report, 'under_review', ['internal_notes' => 'ADMIN_ONLY_NOTE'])->assertOk()->assertJsonPath('report.reviewer', $admin->name);
        $this->decide('reports', $report, 'resolved', ['reason' => 'Contacted the employer and corrected the claim.'])->assertOk();
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'report.resolved', 'target_id' => $report->id, 'actor_id' => $admin->id]);
        $this->decide('reports', $report, 'pending')->assertUnprocessable();
        Sanctum::actingAs($reporter);
        $response = $this->getJson('/api/reports')->assertOk()->assertJsonPath('reports.0.status', 'resolved');
        $this->assertStringNotContainsString('ADMIN_ONLY_NOTE', $response->getContent());
        $this->postJson('/api/reports', $payload)->assertCreated();
        $this->assertDatabaseCount('user_reports', 2);
    }

    public function test_suspended_personal_tokens_stop_authenticating_and_owner_history_is_scoped(): void
    {
        $company = $this->company('verified');
        $job = $this->job($company);
        $victim = $this->account();
        $token = $victim->createToken('victim-browser')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/profile')->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $admin = $this->asAdmin();
        $this->decide('users', $victim, 'suspended')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/profile')->assertUnauthorized();
        $this->flushHeaders();
        $other = $this->company('verified');
        Sanctum::actingAs($other->user);
        $this->getJson('/api/employer/jobs/'.$job->id.'/history')->assertForbidden();
        $this->getJson('/api/employer/verification-history')->assertJsonPath('meta.total', 0);
        Sanctum::actingAs($admin);
        $this->decide('employers', $company, 'rejected')->assertOk();
        Sanctum::actingAs($other->user);
        $this->getJson('/api/employer/verification-history')->assertJsonPath('meta.total', 0);
    }

    public function test_critical_writes_recheck_active_status_after_an_in_flight_authentication_snapshot(): void
    {
        $candidate = $this->account();
        $company = $this->company('verified');
        $job = $this->job($company, ['moderation_status' => 'approved']);
        // Sanctum retains the earlier authenticated model, while the database has changed.
        $staleCandidate = $candidate->fresh();
        $candidate->update(['status' => 'suspended']);
        Sanctum::actingAs($staleCandidate);
        $this->postJson('/api/jobs/'.$job->id.'/applications')->assertForbidden();
        $this->putJson('/api/profile', ['name' => 'Must not change'])->assertForbidden();
        $this->assertDatabaseCount('applications', 0);
        $this->assertNotSame('Must not change', $candidate->fresh()->name);
        $application = Application::create(['user_id' => $candidate->id, 'job_listing_id' => $job->id]);
        $staleOwner = $company->user;
        User::whereKey($staleOwner->id)->update(['status' => 'suspended']);
        Sanctum::actingAs($staleOwner);
        $this->patchJson('/api/employer/applications/'.$application->id, ['status' => 'hired'])->assertForbidden();
        $this->assertSame('submitted', $application->fresh()->status);
    }

    public function test_reporting_validates_targets_categories_and_limits_abuse(): void
    {
        $reporter = $this->account();
        $unrelated = $this->account();
        Sanctum::actingAs($reporter);
        $this->postJson('/api/reports', [])->assertUnprocessable();
        $this->postJson('/api/reports', ['target_type' => 'account', 'target_id' => $unrelated->id, 'category' => 'account_problem', 'description' => 'An unrelated private account.'])->assertNotFound();
        $this->postJson('/api/reports', ['target_type' => 'platform', 'target_id' => 1, 'category' => 'platform_problem', 'description' => 'A platform issue with navigation.'])->assertUnprocessable();
        $this->postJson('/api/reports', ['target_type' => 'platform', 'category' => 'suspicious_job', 'description' => 'A platform issue with navigation.'])->assertUnprocessable();
        $this->postJson('/api/reports', ['target_type' => 'platform', 'category' => 'platform_problem', 'description' => 'A platform issue with navigation.'])->assertCreated();
        $this->postJson('/api/reports', ['target_type' => 'platform', 'category' => 'account_problem', 'description' => 'A platform issue with my account.'])->assertStatus(429);
    }

    public function test_employers_can_report_relevant_accounts_but_not_private_unrelated_accounts(): void
    {
        $company = $this->company('verified');
        $job = $this->job($company, ['moderation_status' => 'approved']);
        $candidate = $this->account();
        Application::create(['user_id' => $candidate->id, 'job_listing_id' => $job->id]);
        Sanctum::actingAs($company->user);
        $this->postJson('/api/reports', ['target_type' => 'account', 'target_id' => $candidate->id, 'category' => 'account_problem', 'description' => 'A problem with this hiring interaction.'])->assertCreated();
    }

    public function test_audit_and_notifications_roll_back_if_recording_an_action_fails(): void
    {
        $company = $this->company();
        $this->asAdmin();
        Event::listen('eloquent.creating: '.AdminAuditLog::class, function () {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            $this->decide('employers', $company, 'verified')->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.AdminAuditLog::class);
        }
        $this->assertSame('pending', $company->fresh()->verification_status);
        $this->assertDatabaseCount('admin_audit_logs', 0);
        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_overview_and_all_admin_collections_use_real_records_filters_and_pagination(): void
    {
        $company = $this->company('verified');
        $pending = $this->company();
        $rejected = $this->company('rejected');
        $this->job($company, ['moderation_status' => 'approved']);
        $this->job($company);
        $this->job($pending);
        $candidate = $this->account();
        $this->account('applicant', ['status' => 'suspended']);
        Application::create(['user_id' => $candidate->id, 'job_listing_id' => JobListing::firstOrFail()->id]);
        UserReport::create(['reporter_id' => $candidate->id, 'target_type' => 'platform', 'category' => 'platform_problem', 'description' => 'A real report description.']);
        $admin = $this->asAdmin();
        $this->getJson('/api/admin/overview')->assertOk()->assertJsonPath('stats.jobseekers', 2)->assertJsonPath('stats.employers', 3)
            ->assertJsonPath('stats.verifiedEmployers', 1)->assertJsonPath('stats.pendingEmployers', 1)->assertJsonPath('stats.rejectedEmployers', 1)
            ->assertJsonPath('stats.jobListings', 3)->assertJsonPath('stats.activeApprovedJobs', 1)->assertJsonPath('stats.pendingJobs', 2)
            ->assertJsonPath('stats.applications', 1)->assertJsonPath('stats.pendingReports', 1)->assertJsonPath('stats.suspendedAccounts', 1);
        $this->getJson('/api/admin/employers?status=rejected&per_page=1')->assertJsonPath('employers.0.id', $rejected->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/jobs?status=pending&employer_id='.$pending->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/users?role=applicant&status=suspended')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/users?query='.urlencode($candidate->email))->assertJsonPath('users.0.id', $candidate->id);
        $this->getJson('/api/admin/users?per_page=2&page=2')->assertJsonCount(2, 'users')->assertJsonPath('meta.total', 6);
        $this->decide('employers', $pending, 'verified')->assertOk();
        $this->getJson('/api/admin/audit-logs?action=employer.verified&actor_id='.$admin->id)->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/reports?query=real&per_page=1')->assertJsonPath('meta.total', 1);
        foreach (['employers', 'jobs', 'users', 'reports', 'audit-logs'] as $path) {
            $this->getJson('/api/admin/'.$path.'?per_page=51')->assertUnprocessable();
        }
    }

    public function test_admin_payloads_and_logs_exclude_secrets_and_applicant_disability_fields(): void
    {
        $candidate = $this->account();
        $candidate->applicantProfile()->create(['pwd_id_number' => 'PRIVATE_MEDICAL_ID', 'phone' => 'PRIVATE_PHONE', 'summary' => 'PRIVATE_DIAGNOSIS']);
        $candidate->createToken('PRIVATE_TOKEN_NAME');
        $this->asAdmin();
        $this->decide('users', $candidate, 'suspended')->assertOk();
        foreach (['/api/admin/users', '/api/admin/users/'.$candidate->id, '/api/admin/audit-logs'] as $url) {
            $response = $this->getJson($url)->assertOk();
            foreach (['PRIVATE_MEDICAL_ID', 'PRIVATE_PHONE', 'PRIVATE_DIAGNOSIS', 'PRIVATE_TOKEN_NAME', 'remember_token', 'password'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_notifications_are_owned_paginated_and_optionally_queued_after_decisions(): void
    {
        Notification::fake();
        config(['administration.email_notifications' => true]);
        $company = $this->company();
        $this->asAdmin();
        $this->decide('employers', $company, 'verified')->assertOk();
        Notification::assertSentTo($company->user, ModerationDecision::class, fn ($notice) => $notice->connection === 'database' && $notice->queue === 'notifications');
        $notice = UserNotification::firstOrFail();
        Sanctum::actingAs($this->account());
        $this->getJson('/api/notifications')->assertJsonPath('meta.total', 0);
        $this->patchJson('/api/notifications/'.$notice->id.'/read')->assertForbidden();
        Sanctum::actingAs($company->user);
        $this->getJson('/api/notifications?per_page=1')->assertJsonCount(1, 'notifications');
        $this->patchJson('/api/notifications/'.$notice->id.'/read')->assertOk();
        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_admin_provisioning_is_controlled_without_public_credentials_or_existing_account_overwrite(): void
    {
        $this->artisan('pwdwork:provision-admin', ['--email' => 'first@admin.example', '--name' => 'First Admin'])
            ->expectsQuestion('Password (12+ characters, mixed case and numbers)', 'Private-Admin-2026!')
            ->expectsQuestion('Confirm password', 'Private-Admin-2026!')->assertSuccessful();
        $admin = User::where('email', 'first@admin.example')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertTrue(Hash::check('Private-Admin-2026!', $admin->password));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'admin.provisioned', 'target_id' => $admin->id, 'actor_role' => 'cli']);
        $this->artisan('pwdwork:provision-admin', ['--email' => $admin->email, '--name' => 'Overwrite'])
            ->expectsQuestion('Password (12+ characters, mixed case and numbers)', 'Private-Admin-2026!')
            ->expectsQuestion('Confirm password', 'Private-Admin-2026!')->assertFailed();
        $this->assertSame('First Admin', $admin->fresh()->name);
    }

    public function test_phase_two_migration_preserves_old_records_and_defaults_jobs_to_pending(): void
    {
        config(['database.connections.phase_two_audit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('phase_two_audit');
        try {
            foreach (glob(database_path('migrations/*.php')) as $file) {
                if (str_contains($file, '000002_add_administration_workflows')) {
                    continue;
                }
                (require $file)->up();
            }
            $userId = DB::table('users')->insertGetId(['name' => 'Keep owner', 'email' => 'keep@migration.example', 'password' => 'KEEP_HASH', 'role' => 'employer']);
            $companyId = DB::table('employers')->insertGetId(['user_id' => $userId, 'company_name' => 'Keep Company', 'verification_status' => 'verified']);
            $jobId = DB::table('job_listings')->insertGetId(['employer_id' => $companyId, 'title' => 'Keep job', 'location' => 'Manila', 'employment_type' => 'Contract', 'work_mode' => 'Remote', 'description' => 'Keep description', 'status' => 'open']);
            DB::table('applications')->insert(['user_id' => $userId, 'job_listing_id' => $jobId, 'status' => 'hired']);
            DB::table('saved_jobs')->insert(['user_id' => $userId, 'job_listing_id' => $jobId]);
            (require database_path('migrations/2026_10_24_000002_add_administration_workflows.php'))->up();
            $this->assertSame('KEEP_HASH', DB::table('users')->value('password'));
            $this->assertSame('verified', DB::table('employers')->value('verification_status'));
            $this->assertSame('open', DB::table('job_listings')->value('status'));
            $this->assertSame('pending', DB::table('job_listings')->value('moderation_status'));
            $this->assertSame('hired', DB::table('applications')->value('status'));
            $this->assertSame(1, DB::table('saved_jobs')->count());
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('phase_two_audit');
        }
    }
}
