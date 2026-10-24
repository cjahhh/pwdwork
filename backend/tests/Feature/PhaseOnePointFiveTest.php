<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetLink;
use App\Models\Accommodation;
use App\Models\Application;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseOnePointFiveTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'applicant', array $fields = []): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', ...$fields]);
    }

    private function job(?User $owner = null, array $fields = []): JobListing
    {
        $owner ??= $this->account('employer');
        $employer = Employer::firstOrCreate(['user_id' => $owner->id], ['company_name' => 'Test Company']);

        return $employer->jobListings()->create([
            'title' => 'Support role', 'location' => 'Manila', 'employment_type' => 'Full Time',
            'work_mode' => 'Remote', 'description' => 'Customer support', 'status' => 'open', ...$fields,
        ]);
    }

    public function test_shortlisting_is_separate_from_hiring_and_legacy_values_are_preserved(): void
    {
        $owner = $this->account('employer');
        $candidate = $this->account();
        $job = $this->job($owner);
        $application = Application::create(['user_id' => $candidate->id, 'job_listing_id' => $job->id, 'status' => 'hired']);
        Sanctum::actingAs($owner);
        $this->getJson('/api/employer/applications')->assertJsonPath('applicants.0.status', 'Hired');
        $this->assertSame('hired', $application->fresh()->status);
        foreach (['shortlisted' => 'Shortlisted', 'hired' => 'Hired', 'reviewing' => 'Reviewing', 'submitted' => 'Submitted', 'rejected' => 'Rejected'] as $code => $label) {
            Sanctum::actingAs($owner);
            $this->patchJson('/api/employer/applications/'.$application->id, ['status' => $code])
                ->assertOk()->assertJsonPath('application.statusCode', $code);
            $this->assertSame($code, $application->fresh()->status);
            Sanctum::actingAs($candidate);
            $this->getJson('/api/applicant/applications')->assertJsonPath('applications.0.status', $label)->assertJsonPath('applications.0.statusCode', $code);
        }
        $application->update(['status' => 'on_hold']);
        $this->getJson('/api/applicant/applications')->assertJsonPath('applications.0.status', 'On hold');
        $this->assertSame('on_hold', $application->fresh()->status);
        Sanctum::actingAs($owner);
        $this->patchJson('/api/employer/applications/'.$application->id, ['status' => 'invented'])->assertUnprocessable();
        $this->assertSame('on_hold', $application->fresh()->status);
    }

    public function test_employer_sees_only_authorized_professional_information_and_opted_in_support(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('resumes/private-candidate.pdf', 'Test resume');
        $owner = $this->account('employer');
        $candidate = $this->account();
        $job = $this->job($owner);
        $profile = $candidate->applicantProfile()->create([
            'headline' => 'Service specialist', 'summary' => 'Professional summary', 'experience' => 'Two years in support',
            'skills' => ['Excel', 'Customer service'], 'phone' => 'PRIVATE_PHONE', 'pwd_id_number' => 'PRIVATE_PWD_ID',
            'resume_path' => 'resumes/private-candidate.pdf',
        ]);
        foreach (['Accessible restroom', 'PRIVATE_DIAGNOSIS'] as $label) {
            $accommodation = Accommodation::create(['label' => $label, 'slug' => str($label)->slug()]);
            $profile->accommodations()->attach($accommodation);
        }
        Sanctum::actingAs($candidate);
        $this->postJson('/api/jobs/'.$job->id.'/applications', ['cover_letter' => 'My relevant cover letter'])->assertCreated();
        $application = Application::firstOrFail();
        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/employer/applications')->assertOk()
            ->assertJsonPath('applicants.0.skills.0', 'Excel')
            ->assertJsonPath('applicants.0.experience', 'Two years in support')
            ->assertJsonPath('applicants.0.summary', 'Professional summary')
            ->assertJsonPath('applicants.0.coverLetter', 'My relevant cover letter')
            ->assertJsonPath('applicants.0.resumeAvailable', true)
            ->assertJsonPath('applicants.0.accommodationsShared', false)->assertJsonCount(0, 'applicants.0.accommodations');
        foreach (['PRIVATE_PHONE', 'PRIVATE_PWD_ID', 'PRIVATE_DIAGNOSIS', 'private-candidate.pdf', 'resume_path', 'pwd_id_number'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        // An employer cannot grant consent on an applicant's behalf.
        $this->patchJson('/api/employer/applications/'.$application->id, ['status' => 'reviewing', 'share_accommodations' => true])
            ->assertOk()->assertJsonPath('application.accommodationsShared', false);
        Sanctum::actingAs($candidate);
        $this->patchJson('/api/applicant/applications/'.$application->id, ['share_accommodations' => true, 'status' => 'hired'])->assertOk();
        $this->assertSame('reviewing', $application->fresh()->status);
        Sanctum::actingAs($owner);
        $this->getJson('/api/employer/applications')->assertJsonPath('applicants.0.accommodations', ['Accessible restroom']);
        $other = $this->account('employer');
        $this->job($other);
        Sanctum::actingAs($other);
        $this->getJson('/api/employer/applications?query=Professional')->assertJsonPath('meta.total', 0);
        $this->patchJson('/api/employer/applications/'.$application->id, ['status' => 'hired'])->assertForbidden();
        Sanctum::actingAs($this->account());
        $this->patchJson('/api/applicant/applications/'.$application->id, ['share_accommodations' => true])->assertForbidden();
        Sanctum::actingAs($candidate);
        $this->patchJson('/api/applicant/applications/'.$application->id, ['share_accommodations' => false])->assertOk();
        Sanctum::actingAs($owner);
        $this->getJson('/api/employer/applications')->assertJsonCount(0, 'applicants.0.accommodations');
    }

    public function test_duplicate_apply_does_not_change_status_cover_letter_or_consent(): void
    {
        $candidate = $this->account();
        $job = $this->job();
        Sanctum::actingAs($candidate);
        $this->postJson('/api/jobs/'.$job->id.'/applications', ['share_accommodations' => true, 'cover_letter' => 'Original'])->assertCreated();
        $application = Application::firstOrFail();
        $application->update(['status' => 'shortlisted']);
        $this->postJson('/api/jobs/'.$job->id.'/applications', ['share_accommodations' => false, 'cover_letter' => 'Replacement'])->assertOk();
        $this->assertSame('shortlisted', $application->fresh()->status);
        $this->assertSame('Original', $application->fresh()->cover_letter);
        $this->assertTrue($application->fresh()->share_accommodations);
    }

    public function test_private_resume_download_requires_application_ownership_and_rejects_unsafe_paths(): void
    {
        Storage::fake('local');
        config(['filesystems.resume_disk' => 'local']);
        $owner = $this->account('employer');
        $candidate = $this->account();
        $job = $this->job($owner);
        $profile = $candidate->applicantProfile()->create(['resume_path' => 'resumes/person.pdf']);
        Storage::disk('local')->put('resumes/person.pdf', 'test resume');
        $application = Application::create(['user_id' => $candidate->id, 'job_listing_id' => $job->id]);
        $url = '/api/employer/applications/'.$application->id.'/resume';
        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs($owner);
        $this->get($url)->assertOk()->assertDownload('resume.pdf');
        foreach (['../secret.pdf', '/absolute.pdf', 'C:\\private.pdf', 'https://example.com/file.pdf', 'resumes/file.html', 'missing.pdf'] as $path) {
            $profile->update(['resume_path' => $path]);
            $this->getJson($url)->assertNotFound();
            $this->getJson('/api/employer/applications')->assertJsonPath('applicants.0.resumeAvailable', false);
        }
        $other = $this->account('employer');
        $this->job($other);
        Sanctum::actingAs($other);
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($candidate);
        $this->getJson($url)->assertForbidden();
    }

    public function test_contract_and_combined_filters_apply_before_stable_pagination(): void
    {
        $owner = $this->account('employer');
        $ids = [];
        $labels = [];
        foreach (['Accessible restroom', 'Adjustable workspace'] as $label) {
            $labels[] = Accommodation::create(['label' => $label, 'slug' => str($label)->slug()])->id;
        }
        foreach (range(1, 5) as $i) {
            $job = $this->job($owner, ['title' => 'Support '.$i, 'employment_type' => 'Contract', 'flexible_schedule' => true]);
            $job->accommodations()->attach($labels);
            $ids[] = $job->id;
        }
        $this->job($owner, ['employment_type' => 'Contract', 'location' => 'Cebu']);
        $this->job($owner, ['employment_type' => 'Contract', 'status' => 'closed']);
        $this->job($owner);
        $filters = ['query' => 'support', 'location' => 'Manila', 'types' => ['Contract', 'Part Time'], 'modes' => ['Remote'],
            'flexible' => 1, 'accommodations' => ['Accessible restroom', 'Adjustable workspace'], 'per_page' => 2];
        $seen = [];
        foreach (range(1, 3) as $page) {
            $data = $this->getJson('/api/jobs?'.http_build_query([...$filters, 'page' => $page]))->assertOk()
                ->assertJsonPath('meta.total', 5)->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.last_page', 3)->json();
            $seen = [...$seen, ...array_column($data['jobs'], 'id')];
        }
        $this->assertSame(array_reverse($ids), $seen);
        foreach (['per_page=51', 'per_page=0', 'page=0', 'page[]=1', 'types[]=Invented'] as $bad) {
            $this->getJson('/api/jobs?'.$bad)->assertUnprocessable();
        }
        $this->getJson('/api/jobs?page=99')->assertOk()->assertJsonCount(0, 'jobs');
        foreach (JobListing::EMPLOYMENT_TYPES as $type) {
            $this->getJson('/api/jobs?'.http_build_query(['types' => [$type]]))->assertOk();
        }
    }

    public function test_all_private_collections_are_paginated_filtered_and_account_scoped(): void
    {
        $owner = $this->account('employer');
        $candidate = $this->account();
        $ids = [];
        foreach (range(1, 4) as $i) {
            $job = $this->job($owner, ['title' => 'Role '.$i]);
            $ids[] = $job->id;
            Application::create(['user_id' => $candidate->id, 'job_listing_id' => $job->id, 'status' => $i === 1 ? 'hired' : 'shortlisted']);
            $candidate->savedJobs()->attach($job);
        }
        $foreign = $this->job();
        Application::create(['user_id' => $this->account()->id, 'job_listing_id' => $foreign->id]);
        Sanctum::actingAs($owner);
        foreach (['/api/employer/jobs' => 'jobs', '/api/employer/applications' => 'applicants'] as $url => $key) {
            $one = $this->getJson($url.'?per_page=2')->assertOk()->assertJsonCount(2, $key)->assertJsonPath('meta.total', 4)->json($key);
            $two = $this->getJson($url.'?per_page=2&page=2')->assertOk()->assertJsonCount(2, $key)->json($key);
            $this->assertCount(4, array_unique(array_column([...$one, ...$two], 'id')));
        }
        $this->getJson('/api/employer/applications?status=hired')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/employer/applications?job_id='.$ids[0])->assertJsonPath('meta.total', 1);
        $this->getJson('/api/employer/applications?query=Role%201')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/employer/jobs?query=Role%201')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/employer/applications?job_id='.$foreign->id)->assertJsonPath('meta.total', 0);
        Sanctum::actingAs($candidate);
        foreach (['/api/applicant/applications' => 'applications', '/api/applicant/saved-jobs' => 'jobs'] as $url => $key) {
            $this->getJson($url.'?per_page=2&page=2')->assertOk()->assertJsonCount(2, $key)->assertJsonPath('meta.total', 4);
        }
        $this->getJson('/api/applicant/applications?status=shortlisted')->assertJsonPath('meta.total', 3);
        // This is the oldest saved job, beyond the first page.
        $this->getJson('/api/applicant/saved-jobs/'.$ids[0])->assertJsonPath('saved', true);
        $this->getJson('/api/applicant/saved-jobs/'.$foreign->id)->assertJsonPath('saved', false);
        Sanctum::actingAs($this->account());
        $this->getJson('/api/applicant/applications')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/applicant/saved-jobs')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/applicant/saved-jobs/'.$ids[0])->assertJsonPath('saved', false);
    }

    public function test_registration_validation_success_and_ip_rate_limit(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/register', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
        $this->postJson('/api/register', ['name' => 'Admin', 'email' => 'admin@test.example', 'password' => 'password', 'role' => 'admin'])->assertUnprocessable();
        $this->postJson('/api/register', ['name' => 'New applicant', 'email' => 'new@test.example', 'password' => 'password', 'role' => 'applicant'])
            ->assertCreated()->assertJsonPath('user.role', 'applicant')->assertJsonMissingPath('token');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.email', 'new@test.example');
        $this->assertDatabaseHas('applicant_profiles', ['user_id' => User::where('email', 'new@test.example')->firstOrFail()->id]);
        $this->postJson('/api/logout')->assertOk();
        // Separate browser requests do not retain cached RequestGuard users.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/register', ['name' => 'New employer', 'company_name' => 'New Company', 'email' => 'employer@test.example', 'password' => 'password', 'role' => 'employer'])
            ->assertCreated()->assertJsonPath('user.company_name', 'New Company');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/register', [])->assertUnprocessable();
        $this->postJson('/api/register', [])->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/register', [])->assertUnprocessable();
    }

    public function test_registration_uses_only_trusted_proxy_client_ips_and_ignores_forged_headers(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['trustedproxy.proxies' => ['10.0.0.1']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1']);
        $this->withHeader('X-Forwarded-For', '198.51.100.10');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/register', [])->assertStatus(429);
        $this->withHeader('X-Forwarded-For', '198.51.100.11')->postJson('/api/register', [])->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20']);
        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('X-Forwarded-For', '198.51.100.'.(20 + $i))->postJson('/api/register', [])->assertUnprocessable();
        }
        $this->withHeader('X-Forwarded-For', '198.51.100.99')->postJson('/api/register', [])->assertStatus(429);
    }

    public function test_profile_experience_persists_and_omitted_experience_is_not_erased(): void
    {
        Sanctum::actingAs($this->account());
        $this->putJson('/api/profile', ['name' => 'Candidate', 'experience' => 'Library volunteering', 'skills' => ['Cataloguing']])
            ->assertOk()->assertJsonPath('profile.experience', 'Library volunteering');
        $this->putJson('/api/profile', ['name' => 'Updated'])->assertOk()->assertJsonPath('profile.experience', 'Library volunteering');
    }

    public function test_recovery_uses_the_configured_async_connection_and_queue(): void
    {
        Queue::fake();
        config(['queue.password_reset_connection' => 'redis', 'queue.password_reset_queue' => 'recovery']);
        $this->postJson('/api/forgot-password', ['email' => 'unknown@test.example'])->assertStatus(202);
        Queue::assertPushed(SendPasswordResetLink::class, fn ($job) => $job->connection === 'redis' && $job->queue === 'recovery');
    }

    public function test_reset_revokes_only_the_target_users_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->account();
        $other = $this->account();
        foreach ([$user, $other] as $account) {
            DB::table('sessions')->insert(['id' => 'session-'.$account->id, 'user_id' => $account->id, 'payload' => '', 'last_activity' => time()]);
        }
        $token = Password::createToken($user);
        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token,
            'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
    }

    public function test_cors_credentials_are_allowed_only_for_configured_origins(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.com']]);
        $this->withHeaders(['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'POST'])
            ->options('/api/register')->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
        // With one allowed origin the CORS library can emit that fixed origin
        // on every response; a browser still rejects a different request origin.
        $response = $this->withHeader('Origin', 'https://untrusted.example')->options('/api/register');
        $this->assertNotSame('https://untrusted.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_additive_migration_preserves_existing_hired_applications_and_defaults_to_private(): void
    {
        // Build the old schema in a second, entirely in-memory connection.
        config(['database.connections.migration_audit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('migration_audit');
        try {
            $schema = DB::connection()->getSchemaBuilder();
            $schema->create('applicant_profiles', function ($table) {
                $table->id();
                $table->text('summary')->nullable();
            });
            $schema->create('applications', function ($table) {
                $table->id();
                $table->string('status');
            });
            DB::table('applicant_profiles')->insert(['summary' => 'Keep this profile']);
            DB::table('applications')->insert(['status' => 'hired']);
            $migration = require database_path('migrations/2026_10_24_000001_add_applicant_experience_and_application_sharing.php');
            $migration->up();
            $this->assertSame('hired', DB::table('applications')->value('status'));
            $this->assertSame(0, DB::table('applications')->value('share_accommodations'));
            $this->assertSame('Keep this profile', DB::table('applicant_profiles')->value('summary'));
            $this->assertNull(DB::table('applicant_profiles')->value('experience'));
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('migration_audit');
        }
    }
}
