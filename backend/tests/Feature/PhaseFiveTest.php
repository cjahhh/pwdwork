<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Employer;
use App\Models\Resume;
use App\Models\User;
use App\Services\ResumeRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseFiveTest extends TestCase
{
    use RefreshDatabase;

    private User $applicant;

    private User $employer;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applicant = User::factory()->create(['role' => 'applicant']);
        $this->employer = User::factory()->create(['role' => 'employer']);
        $company = Employer::create(['user_id' => $this->employer->id, 'company_name' => 'Resume Company', 'verification_status' => 'verified']);
        $job = $company->jobListings()->create(['title' => 'Support', 'description' => 'Support work', 'employment_type' => 'Contract', 'work_mode' => 'Remote', 'location' => 'Manila', 'status' => 'open', 'moderation_status' => 'approved']);
        $this->application = Application::create(['user_id' => $this->applicant->id, 'job_listing_id' => $job->id, 'status' => 'submitted']);
        Sanctum::actingAs($this->applicant);
    }

    private function payload(): array
    {
        return ['title' => 'Support resume', 'template' => 'classic', 'content' => ['personal' => ['name' => 'Applicant Example', 'email' => 'example@example.com'], 'summary' => 'Reliable support', 'education' => [['heading' => 'Diploma', 'organization' => 'School', 'dates' => '2025', 'details' => 'Completed training']], 'volunteer' => [['heading' => 'Community helper']], 'skills' => [['heading' => 'Customer service']]]];
    }

    private function createResume(): array
    {
        return $this->postJson('/api/applicant/resumes', $this->payload())->assertCreated()->json('resume');
    }

    private function sharePath(): string
    {
        return '/api/applications/'.$this->application->id.'/built-resume';
    }

    private function share(array $resume): void
    {
        $this->putJson($this->sharePath(), ['resume_id' => $resume['id'], 'consent' => true])->assertOk();
    }

    public function test_create_edit_sections_preview_duplicate_and_delete(): void
    {
        $resume = $this->createResume();
        $this->getJson('/api/applicant/resumes')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/applicant/resumes/'.$resume['id'])->assertOk()->assertJsonPath('resume.content.education.0.heading', 'Diploma');
        $payload = $this->payload();
        $payload['content']['languages'] = [['heading' => 'English', 'organization' => 'Fluent']];
        $this->putJson('/api/applicant/resumes/'.$resume['id'], [...$payload, 'version' => 1])->assertOk()->assertJsonPath('resume.version', 2)->assertJsonPath('resume.content.languages.0.heading', 'English');
        $this->putJson('/api/applicant/resumes/'.$resume['id'], [...$payload, 'version' => 1])->assertStatus(409);
        $this->get('/api/applicant/resumes/'.$resume['id'].'/preview')->assertOk()->assertSee('English')->assertHeader('Cache-Control', 'no-store, private');
        $copy = $this->postJson('/api/applicant/resumes/'.$resume['id'].'/duplicate')->assertCreated()->json('resume');
        $this->assertSame(1, $copy['version']);
        $this->assertSame($payload['content'], $copy['content']);
        $this->deleteJson('/api/applicant/resumes/'.$resume['id'])->assertOk();
        $this->getJson('/api/applicant/resumes/'.$resume['id'])->assertNotFound();
    }

    public function test_only_owner_can_access_edit_delete_duplicate_preview_or_download(): void
    {
        $resume = $this->createResume();
        Sanctum::actingAs(User::factory()->create(['role' => 'applicant']));
        $path = '/api/applicant/resumes/'.$resume['id'];
        foreach (['', '/preview', '/pdf'] as $suffix) {
            $this->getJson($path.$suffix)->assertNotFound();
        }
        $this->putJson($path, [...$this->payload(), 'version' => 1])->assertNotFound();
        $this->deleteJson($path)->assertNotFound();
        $this->postJson($path.'/duplicate')->assertNotFound();
        $this->putJson($this->sharePath(), ['resume_id' => $resume['id'], 'consent' => true])->assertNotFound();
    }

    public function test_employer_only_sees_explicit_snapshot_and_revocation_blocks_access(): void
    {
        $resume = $this->createResume();
        Sanctum::actingAs($this->employer);
        $this->getJson($this->sharePath())->assertOk()->assertJsonPath('share', null);
        $this->getJson($this->sharePath().'/pdf')->assertNotFound();
        $this->getJson('/api/applicant/resumes/'.$resume['id'])->assertForbidden();
        Sanctum::actingAs($this->applicant);
        $this->share($resume);
        $payload = $this->payload();
        $payload['content']['summary'] = 'Changed private draft';
        $this->putJson('/api/applicant/resumes/'.$resume['id'], [...$payload, 'version' => 1])->assertOk();
        Sanctum::actingAs($this->employer);
        $this->get($this->sharePath().'/preview')->assertOk()->assertSee('Reliable support')->assertDontSee('Changed private draft');
        $this->get($this->sharePath().'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Sanctum::actingAs($this->applicant);
        $this->deleteJson($this->sharePath())->assertOk();
        Sanctum::actingAs($this->employer);
        $this->getJson($this->sharePath().'/preview')->assertNotFound();
        $this->getJson($this->sharePath().'/pdf')->assertNotFound();
        $this->assertSame('submitted', $this->application->fresh()->status);
        $this->assertFalse($this->application->fresh()->share_accommodations);
    }

    public function test_other_company_cannot_access_shared_resume(): void
    {
        $this->share($this->createResume());
        $other = User::factory()->create(['role' => 'employer']);
        Employer::create(['user_id' => $other->id, 'company_name' => 'Other Company']);
        Sanctum::actingAs($other);
        foreach (['', '/preview', '/pdf'] as $suffix) {
            $this->getJson($this->sharePath().$suffix)->assertNotFound();
        }
        $this->deleteJson($this->sharePath())->assertForbidden();
    }

    public function test_consent_and_owned_resume_are_required_and_deletion_preserves_snapshot(): void
    {
        $resume = $this->createResume();
        $this->putJson($this->sharePath(), ['resume_id' => $resume['id']])->assertUnprocessable();
        $foreign = Resume::create(['user_id' => User::factory()->create()->id, ...$this->payload()]);
        $this->putJson($this->sharePath(), ['resume_id' => $foreign->id, 'consent' => true])->assertNotFound();
        $this->share($resume);
        $this->deleteJson('/api/applicant/resumes/'.$resume['id'])->assertOk();
        Sanctum::actingAs($this->employer);
        $this->get($this->sharePath().'/preview')->assertOk()->assertSee('Reliable support');
    }

    public function test_import_requires_consent_and_excludes_private_fields_and_does_not_change_profile(): void
    {
        $this->applicant->applicantProfile()->create(['location' => 'Manila', 'skills' => ['Support'], 'experience' => 'Volunteer', 'summary' => 'Profile summary', 'pwd_id_number' => 'SECRET-ID', 'phone' => 'PRIVATE-PHONE']);
        $this->postJson('/api/applicant/resumes/import-profile')->assertUnprocessable();
        $data = $this->postJson('/api/applicant/resumes/import-profile', ['consent' => true])->assertOk()->assertJsonPath('content.work.0.details', 'Volunteer')->getContent();
        $this->assertStringNotContainsString('SECRET-ID', $data);
        $this->assertStringNotContainsString('PRIVATE-PHONE', $data);
        $this->createResume();
        $this->assertSame('Profile summary', $this->applicant->applicantProfile->summary);
    }

    public function test_html_is_escaped_and_unknown_private_fields_and_oversized_input_are_rejected(): void
    {
        $payload = $this->payload();
        $payload['content']['summary'] = '<script>alert(1)</script><img src="file:///secret">';
        $id = $this->postJson('/api/applicant/resumes', $payload)->assertCreated()->json('resume.id');
        $this->get('/api/applicant/resumes/'.$id.'/preview')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)->assertDontSee('<img', false);
        $payload['content']['pwd_id_number'] = 'secret';
        $this->postJson('/api/applicant/resumes', $payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['content']['summary'] = str_repeat('a', 4001);
        $this->postJson('/api/applicant/resumes', $payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['content']['skills'] = array_fill(0, 21, ['heading' => 'Skill']);
        $this->postJson('/api/applicant/resumes', $payload)->assertUnprocessable();
        $payload['content']['skills'] = ['unexpected-key' => ['heading' => 'Skill']];
        $this->postJson('/api/applicant/resumes', $payload)->assertUnprocessable();
    }

    public function test_both_templates_generate_real_pdf_without_private_profile_data(): void
    {
        $this->applicant->applicantProfile()->create(['pwd_id_number' => 'SECRET-ID', 'experience' => 'PRIVATE-EXPERIENCE']);
        foreach (['classic', 'modern'] as $template) {
            $payload = $this->payload();
            $payload['template'] = $template;
            $id = $this->postJson('/api/applicant/resumes', $payload)->assertCreated()->json('resume.id');
            $pdf = $this->get('/api/applicant/resumes/'.$id.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $pdf->getContent());
            $this->assertGreaterThan(10000, strlen($pdf->getContent()));
            $this->get('/api/applicant/resumes/'.$id.'/preview')->assertDontSee('SECRET-ID')->assertDontSee('PRIVATE-EXPERIENCE');
        }
    }

    public function test_unauthenticated_access_is_denied(): void
    {
        $this->app->make('auth')->forgetGuards();
        foreach (['/api/applicant/resumes', '/api/applicant/resumes/1/pdf', $this->sharePath().'/pdf'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }

    public function test_debug_errors_never_expose_paths_sql_or_resume_data(): void
    {
        config(['app.debug' => true]);
        $this->getJson('/api/applicant/resumes/999/pdf')->assertNotFound()->assertExactJson(['message' => 'Resume not found or you do not have permission to access it.']);
        $this->mock(ResumeRenderer::class, function ($mock) {
            $mock->shouldReceive('pdf')->andThrow(new \RuntimeException('SECRET-DATA C:/private/resume SQL details'));
        });
        $resume = $this->createResume();
        $this->getJson('/api/applicant/resumes/'.$resume['id'].'/pdf')->assertStatus(500)->assertExactJson(['message' => 'Resumes are temporarily unavailable. Please try again later.']);
    }

    public function test_missing_migration_fails_safely_without_breaking_existing_applications(): void
    {
        Schema::drop('resume_application_shares');
        Schema::drop('resumes');
        $this->getJson('/api/applicant/resumes')->assertStatus(503);
        $this->getJson('/api/applicant/applications')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_in_flight_suspension_blocks_resume_writes(): void
    {
        User::whereKey($this->applicant->id)->update(['status' => 'suspended']);
        $this->postJson('/api/applicant/resumes', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('resumes', 0);
    }
}
