<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetLink;
use App\Models\Accommodation;
use App\Models\Application;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseOneTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'applicant'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function job(?User $owner = null, array $fields = []): JobListing
    {
        $owner ??= $this->account('employer');
        $employer = Employer::firstOrCreate(['user_id' => $owner->id], ['company_name' => 'Test Company']);

        return $employer->jobListings()->create(array_merge(['title' => 'Support role', 'location' => 'Manila', 'employment_type' => 'Full Time', 'work_mode' => 'Remote', 'description' => 'Customer support', 'status' => 'open'], $fields));
    }

    public function test_empty_jobs_are_empty_and_bad_filters_are_rejected(): void
    {
        $this->getJson('/api/jobs')->assertOk()->assertExactJson(['jobs' => []]);
        $this->getJson('/api/jobs?modes[]=Invented')->assertUnprocessable();
    }

    public function test_combined_filters_require_all_selected_accommodations(): void
    {
        $good = $this->job(null, ['flexible_schedule' => true]);
        $other = $this->job(null, ['title' => 'Other', 'work_mode' => 'Onsite']);
        foreach (['Accessible restroom', 'Adjustable workspace'] as $label) {
            $a = Accommodation::create(['label' => $label, 'slug' => str($label)->slug()]);
            $good->accommodations()->attach($a);
        }
        $url = '/api/jobs?'.http_build_query(['query' => 'support', 'location' => 'Manila', 'modes' => ['Remote', 'Hybrid'], 'types' => ['Full Time'], 'flexible' => 1, 'accommodations' => ['Accessible restroom', 'Adjustable workspace']]);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'jobs')->assertJsonPath('jobs.0.id', $good->id)->assertJsonPath('jobs.0.accommodationsVerified', false);
        $this->getJson('/api/jobs?'.http_build_query(['accommodations' => ['Assistive technology support']]))->assertJsonCount(0, 'jobs');
    }

    public function test_profiles_persist_and_cannot_change_email_or_verification(): void
    {
        $user = $this->account();
        Sanctum::actingAs($user);
        $this->putJson('/api/profile', ['name' => 'Updated', 'email' => 'attacker@example.com', 'headline' => 'Analyst', 'bio' => 'Experience', 'skills' => ['Excel'], 'accommodations' => ['Accessible restroom']])->assertOk()->assertJsonPath('profile.name', 'Updated');
        $this->getJson('/api/profile')->assertJsonPath('profile.skills.0', 'Excel')->assertJsonPath('profile.email', $user->email);
        $owner = $this->account('employer');
        $this->job($owner);
        Sanctum::actingAs($owner);
        $this->putJson('/api/profile', ['companyName' => 'New company', 'verificationStatus' => 'verified'])->assertOk()->assertJsonPath('profile.verificationStatus', 'pending');
    }

    public function test_saved_jobs_are_idempotent_persistent_and_account_scoped(): void
    {
        $job = $this->job();
        $a = $this->account();
        $b = $this->account();
        Sanctum::actingAs($a);
        $this->putJson('/api/applicant/saved-jobs/'.$job->id)->assertOk();
        $this->putJson('/api/applicant/saved-jobs/'.$job->id)->assertOk();
        $this->getJson('/api/applicant/saved-jobs')->assertJsonCount(1, 'jobs');
        Sanctum::actingAs($b);
        $this->getJson('/api/applicant/saved-jobs')->assertJsonCount(0, 'jobs');
        Sanctum::actingAs($a);
        $this->deleteJson('/api/applicant/saved-jobs/'.$job->id)->assertOk();
        $this->assertDatabaseCount('saved_jobs', 0);
    }

    public function test_applications_are_idempotent_and_closed_jobs_reject_applications(): void
    {
        $user = $this->account();
        $job = $this->job();
        Sanctum::actingAs($user);
        $this->postJson('/api/jobs/'.$job->id.'/applications')->assertCreated();
        $this->postJson('/api/jobs/'.$job->id.'/applications')->assertOk();
        $this->assertDatabaseCount('applications', 1);
        $job->update(['status' => 'closed']);
        $this->postJson('/api/jobs/'.$job->id.'/applications')->assertUnprocessable();
    }

    public function test_employer_ownership_and_interview_validation(): void
    {
        $owner = $this->account('employer');
        $job = $this->job($owner);
        $candidate = $this->account();
        $application = Application::create(['user_id' => $candidate->id, 'job_listing_id' => $job->id, 'status' => 'submitted']);
        $other = $this->account('employer');
        $this->job($other);
        Sanctum::actingAs($other);
        $this->patchJson('/api/employer/jobs/'.$job->id, ['title' => 'Stolen'])->assertForbidden();
        $this->patchJson('/api/employer/applications/'.$application->id, ['status' => 'rejected'])->assertForbidden();
        Sanctum::actingAs($owner);
        $this->patchJson('/api/employer/applications/'.$application->id, ['status' => 'interview'])->assertUnprocessable();
        $date = now()->addDays(2)->toDateString();
        $this->patchJson('/api/employer/applications/'.$application->id, ['status' => 'interview', 'interview_date' => $date, 'interview_time' => '09:00'])->assertOk();
        Sanctum::actingAs($candidate);
        $this->getJson('/api/applicant/applications')->assertOk()->assertJsonPath('applications.0.interviewDate', $date);
    }

    public function test_employer_can_store_and_update_reported_accommodations(): void
    {
        $owner = $this->account('employer');
        $this->job($owner);
        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/employer/jobs', ['title' => 'New', 'location' => 'Cebu', 'employment_type' => 'Part Time', 'work_mode' => 'Hybrid', 'description' => 'Role', 'accommodations' => ['Accessible restroom'], 'flexible_schedule' => true])->assertCreated()->assertJsonPath('job.flexibleSchedule', true);
        $id = $response->json('job.id');
        $this->patchJson('/api/employer/jobs/'.$id, ['accommodations' => ['Accessible interview options'], 'flexible_schedule' => false])->assertOk()->assertJsonPath('job.accommodations.0.label', 'Accessible interview options')->assertJsonPath('job.flexibleSchedule', false);
        $this->patchJson('/api/employer/jobs/'.$id, ['accommodations' => ['Verified everything']])->assertUnprocessable();
    }

    public function test_authentication_and_role_boundaries(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/profile')->assertUnauthorized();
        Sanctum::actingAs($this->account('employer'));
        $this->getJson('/api/applicant/saved-jobs')->assertForbidden();
    }

    public function test_reset_requests_are_generic_deliver_notification_and_are_rate_limited(): void
    {
        Notification::fake();
        Queue::fake();
        $user = $this->account();
        $known = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(202);
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])->assertStatus(202);
        $this->assertSame($known->json(), $unknown->json());
        Queue::assertPushed(SendPasswordResetLink::class, 2);
        (new SendPasswordResetLink($user->email))->handle();
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            $this->assertStringContainsString('/reset-password?token=', $mail->actionUrl);

            return true;
        });
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])->assertStatus(202);
        }
        $this->postJson('/api/forgot-password', ['email' => 'missing@example.com'])->assertStatus(429);
    }

    public function test_password_reset_validates_confirmation_revokes_tokens_and_is_single_use(): void
    {
        $user = $this->account();
        $user->createToken('test');
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'wrong'];
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
        $payload['password_confirmation'] = 'new-password';
        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
    }

    public function test_queue_failure_is_a_visible_generic_error(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('Queue unavailable'));
        $this->postJson('/api/forgot-password', ['email' => 'unknown@example.com'])->assertStatus(503)->assertJsonPath('message', 'Password recovery is temporarily unavailable. Please try again later.');
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $user = $this->account();
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertUnprocessable();
    }

    public function test_cookie_login_restores_user_and_logout_preserves_saved_data(): void
    {
        $user = $this->account();
        $job = $this->job();
        $user->savedJobs()->attach($job);
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk()->assertJsonMissingPath('token');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->postJson('/api/logout')->assertOk();
        $this->assertDatabaseHas('saved_jobs', ['user_id' => $user->id, 'job_listing_id' => $job->id]);
    }

    public function test_expired_tokens_and_disabled_accounts_are_rejected(): void
    {
        $user = $this->account();
        $token = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertUnauthorized();
        $this->flushHeaders();
        $user->update(['status' => 'inactive']);
        Sanctum::actingAs($user);
        $this->getJson('/api/user')->assertForbidden();
    }

    public function test_first_party_writes_require_csrf_in_non_test_environment(): void
    {
        $this->app['env'] = 'local';
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'password'])->assertStatus(419);
    }

    public function test_demo_seed_keeps_old_jobs_and_existing_passwords(): void
    {
        $user = User::factory()->create(['email' => 'applicant@pwdwork.org', 'password' => 'custom-password']);
        $legacy = $this->job(null, ['title' => 'Senior UX/UI Accessibility Designer']);
        $this->seed(DatabaseSeeder::class);
        $this->assertTrue(Hash::check('custom-password', $user->fresh()->password));
        $this->assertDatabaseHas('job_listings', ['id' => $legacy->id]);
        $this->assertDatabaseHas('job_listings', ['title' => 'Customer Service Representative']);
    }
}
