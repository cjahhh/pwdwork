<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRequest;
use App\Models\Application;
use App\Models\Employer;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AccommodationRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseFourTest extends TestCase
{
    use RefreshDatabase;

    private User $applicant;

    private User $owner;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applicant = $this->account();
        $this->owner = $this->account('employer');
        $company = Employer::create(['user_id' => $this->owner->id, 'company_name' => 'Access Company', 'verification_status' => 'verified']);
        $job = $company->jobListings()->create(['title' => 'Support role', 'description' => 'Excel support', 'employment_type' => 'Contract', 'work_mode' => 'Remote', 'location' => 'Manila', 'status' => 'open', 'moderation_status' => 'approved']);
        $this->application = Application::create(['user_id' => $this->applicant->id, 'job_listing_id' => $job->id, 'status' => 'submitted']);
        Sanctum::actingAs($this->applicant);
    }

    private function account(string $role = 'applicant'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function submission(array $extra = []): array
    {
        return ['application_id' => $this->application->id, 'action_key' => (string) Str::uuid(), 'share_request' => true,
            'categories' => ['Accessible restroom'], 'stage' => 'interview', ...$extra];
    }

    private function createRequest(array $extra = []): array
    {
        return $this->postJson('/api/accommodation-requests', $this->submission($extra))->assertCreated()->json('request');
    }

    private function action(array $record, string $action, array $extra = [])
    {
        return $this->postJson('/api/accommodation-requests/'.$record['id'].'/actions', [
            'action' => $action, 'expected_version' => $record['version'], 'action_key' => (string) Str::uuid(), ...$extra,
        ]);
    }

    public function test_creation_is_intentional_optional_and_does_not_share_profile_or_change_application(): void
    {
        $profile = $this->applicant->applicantProfile()->create(['summary' => 'PRIVATE SUMMARY', 'pwd_id_number' => 'PRIVATE ID']);
        $private = Accommodation::create(['label' => 'PRIVATE PREFERENCE', 'slug' => 'private-preference']);
        $profile->accommodations()->attach($private);
        $this->postJson('/api/accommodation-requests', $this->submission(['share_request' => false]))->assertUnprocessable();
        $request = $this->createRequest();
        $this->assertNull($request['details']);
        $this->assertFalse($this->application->fresh()->share_accommodations);
        $this->assertSame('submitted', $this->application->fresh()->status);
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/accommodation-requests/'.$request['id'])->assertOk()->assertDontSee('PRIVATE SUMMARY')->assertDontSee('PRIVATE ID')->assertDontSee('PRIVATE PREFERENCE');
        $this->getJson('/api/employer/applications')->assertJsonPath('applicants.0.accommodations', []);
        $this->getJson('/api/notifications')->assertJsonPath('meta.total', 1)->assertDontSee('Accessible restroom');
        $this->assertSame(0, DB::table('admin_audit_logs')->count());
    }

    public function test_private_endpoints_are_role_and_participant_scoped(): void
    {
        $record = $this->createRequest(['details' => 'PRIVATE_REQUEST_TEXT']);
        foreach ([$this->account(), $this->account('employer')] as $outsider) {
            Sanctum::actingAs($outsider);
            foreach (['', '/messages', '/events'] as $suffix) {
                $this->getJson('/api/accommodation-requests/'.$record['id'].$suffix)->assertNotFound()->assertDontSee('PRIVATE_REQUEST_TEXT');
            }
            $this->getJson('/api/accommodation-requests?query=Support')->assertJsonPath('meta.total', 0);
            $this->action($record, 'message', ['text' => 'Unauthorized message'])->assertNotFound();
        }
        Sanctum::actingAs($this->account());
        $this->postJson('/api/accommodation-requests', $this->submission())->assertNotFound();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/accommodation-requests', $this->submission())->assertForbidden();
        Sanctum::actingAs($this->account('admin'));
        foreach (['', '/'.$record['id'], '/'.$record['id'].'/messages', '/'.$record['id'].'/events', '/catalog'] as $suffix) {
            $this->getJson('/api/accommodation-requests'.$suffix)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/accommodation-requests')->assertUnauthorized();
    }

    public function test_direct_unauthorized_access_returns_only_safe_errors_even_with_debug_enabled(): void
    {
        $record = $this->createRequest(['details' => 'PRIVATE_REQUEST_TEXT']);
        $outsider = $this->account('employer');
        Employer::create(['user_id' => $outsider->id, 'company_name' => 'Unrelated Company']);
        Sanctum::actingAs($outsider);
        $safe = ['message' => 'Accommodation request not found or you do not have permission to view it.'];
        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);
            foreach ([$record['id'], 999999] as $id) {
                foreach (['', '/messages', '/events'] as $suffix) {
                    $this->getJson('/api/accommodation-requests/'.$id.$suffix)
                        ->assertNotFound()->assertExactJson($safe)->assertHeader('Cache-Control', 'no-store, private');
                }
            }
            $this->action($record, 'message', ['text' => 'Unauthorized write attempt'])->assertNotFound()->assertExactJson($safe);
        }
        $this->assertSame(0, DB::table('accommodation_request_messages')->count());
        $this->assertSame(1, UserNotification::count());
    }

    public function test_private_request_exceptions_are_sanitized_and_validation_remains_useful(): void
    {
        $record = $this->createRequest();
        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);
            $this->mock(AccommodationRequestService::class, function ($mock) {
                $mock->shouldReceive('payload')->once()->andThrow(new \RuntimeException('SQLSTATE internal database detail App\\Models\\AccommodationRequest stack trace'));
            });
            $this->getJson('/api/accommodation-requests/'.$record['id'])->assertStatus(500)->assertExactJson([
                'message' => 'Accommodation requests are temporarily unavailable. Please try again later.',
            ]);
        }
        $this->postJson('/api/accommodation-requests', $this->submission(['categories' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors('categories')->assertJsonMissingPath('trace')->assertJsonMissingPath('exception');
    }

    public function test_idempotency_conflicts_and_stale_updates_do_not_duplicate_notifications(): void
    {
        $data = $this->submission();
        $record = $this->postJson('/api/accommodation-requests', $data)->assertCreated()->json('request');
        $this->postJson('/api/accommodation-requests', $data)->assertOk()->assertJsonPath('request.id', $record['id']);
        $this->postJson('/api/accommodation-requests', [...$data, 'details' => 'Changed content'])->assertConflict();
        $this->assertSame(1, UserNotification::count());
        Sanctum::actingAs($this->owner);
        $action = ['action_key' => (string) Str::uuid(), 'expected_version' => 1, 'action' => 'acknowledge'];
        $url = '/api/accommodation-requests/'.$record['id'].'/actions';
        $this->postJson($url, $action)->assertOk()->assertJsonPath('request.version', 2);
        $this->postJson($url, $action)->assertOk()->assertJsonPath('request.version', 2);
        $this->postJson($url, [...$action, 'text' => 'Different action content'])->assertConflict();
        $this->action($record, 'propose', ['text' => 'A practical alternative'])->assertConflict();
        $this->assertSame(2, UserNotification::count());
        $this->assertSame(2, DB::table('accommodation_request_events')->count());
    }

    public function test_alternatives_require_applicant_acceptance_and_invalid_actions_are_rejected(): void
    {
        $record = $this->createRequest();
        $this->action($record, 'agree', ['text' => 'Confirm all requests'])->assertForbidden();
        $this->action($record, 'accept')->assertConflict();
        Sanctum::actingAs($this->owner);
        $this->action($record, 'withdraw')->assertForbidden();
        $this->action($record, 'propose')->assertUnprocessable();
        $record = $this->action($record, 'propose', ['text' => 'Use a fully accessible video meeting'])->assertOk()->assertJsonPath('request.status', 'alternative_proposed')->json('request');
        $this->action($record, 'agree', ['text' => 'Bypass applicant consent'])->assertConflict();
        $this->action($record, 'accept')->assertForbidden();
        Sanctum::actingAs($this->applicant);
        $record = $this->action($record, 'decline', ['text' => 'Please discuss another option'])->assertOk()->assertJsonPath('request.status', 'under_discussion')->json('request');
        Sanctum::actingAs($this->owner);
        $record = $this->action($record, 'propose', ['text' => 'An accessible interview room is available'])->assertOk()->json('request');
        Sanctum::actingAs($this->applicant);
        $record = $this->action($record, 'accept')->assertOk()->assertJsonPath('request.status', 'agreed')->json('request');
        $this->assertSame('submitted', $this->application->fresh()->status);
        $this->getJson('/api/applicant/applications')->assertJsonPath('applications.0.confirmedArrangements.0.id', $record['id']);
        $this->getJson('/api/accommodation-requests/'.$record['id'].'/events')->assertOk()->assertJsonPath('meta.total', 5);
    }

    public function test_withdrawal_revokes_employer_reads_messages_and_actions_but_owner_keeps_history(): void
    {
        $record = $this->createRequest();
        $record = $this->action($record, 'message', ['text' => 'Private practical details'])->assertOk()->json('request');
        $record = $this->action($record, 'withdraw')->assertOk()->assertJsonPath('request.status', 'withdrawn')->json('request');
        $this->getJson('/api/accommodation-requests/'.$record['id'].'/messages')->assertOk()->assertSee('Private practical details');
        $this->action($record, 'message', ['text' => 'No further communication'])->assertConflict();
        $this->action($record, 'edit', ['share_request' => true, 'categories' => ['Accessible restroom'], 'stage' => 'interview'])->assertConflict();
        Sanctum::actingAs($this->owner);
        foreach (['', '/messages', '/events'] as $suffix) {
            $this->getJson('/api/accommodation-requests/'.$record['id'].$suffix)->assertNotFound();
        }
        $this->action($record, 'propose', ['text' => 'No longer authorized'])->assertNotFound();
        $this->getJson('/api/accommodation-requests')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/employer/applications')->assertJsonPath('applicants.0.confirmedArrangements', []);
        $this->assertSame('/employer/accommodation-requests', UserNotification::latest('id')->first()->action_url);
    }

    public function test_messages_are_validated_paginated_immutable_and_retry_safe(): void
    {
        $record = $this->createRequest();
        $this->action($record, 'message', ['text' => str_repeat('x', 2001)])->assertUnprocessable();
        $data = ['action' => 'message', 'action_key' => (string) Str::uuid(), 'expected_version' => 1, 'text' => '<script>alert("xss")</script>'];
        $url = '/api/accommodation-requests/'.$record['id'].'/actions';
        $record = $this->postJson($url, $data)->assertOk()->json('request');
        $this->postJson($url, $data)->assertOk();
        Sanctum::actingAs($this->owner);
        $record = $this->action($record, 'message', ['text' => 'Please clarify your preferred format'])->assertOk()->json('request');
        $this->getJson('/api/accommodation-requests/'.$record['id'].'/messages?per_page=1')->assertJsonPath('meta.total', 2)->assertJsonPath('messages.0.sender', 'employer');
        $this->getJson('/api/accommodation-requests/'.$record['id'].'/messages?per_page=1&page=2')->assertJsonPath('messages.0.sender', 'applicant');
        $this->patchJson('/api/accommodation-requests/'.$record['id'].'/messages/1', ['body' => 'Changed'])->assertNotFound();
        $this->deleteJson('/api/accommodation-requests/'.$record['id'].'/messages/1')->assertNotFound();
        $this->assertSame(3, UserNotification::count());
    }

    public function test_edits_keep_history_and_reset_previous_agreement_and_unable_never_changes_hiring(): void
    {
        $record = $this->createRequest(['details' => 'Initial practical request']);
        Sanctum::actingAs($this->owner);
        $this->action($record, 'unable')->assertUnprocessable();
        $record = $this->action($record, 'unable', ['text' => 'This arrangement is unavailable; please discuss options'])->assertOk()->json('request');
        $this->assertSame('submitted', $this->application->fresh()->status);
        Sanctum::actingAs($this->applicant);
        $record = $this->action($record, 'edit', ['share_request' => true, 'categories' => ['Accessible interview options'], 'stage' => 'recruitment', 'details' => 'Updated practical arrangement'])->assertOk()->assertJsonPath('request.status', 'submitted')->json('request');
        $this->getJson('/api/accommodation-requests/'.$record['id'].'/events')->assertJsonPath('events.0.snapshot.details', 'Updated practical arrangement')->assertSee('Initial practical request');
    }

    public function test_interview_requests_survive_rescheduling_with_visible_schedule_context(): void
    {
        $record = $this->createRequest();
        $this->assertNull($this->application->fresh()->interview_date);
        Sanctum::actingAs($this->owner);
        $date = now()->addDays(5)->toDateString();
        $this->patchJson('/api/employer/applications/'.$this->application->id, ['status' => 'interview', 'interview_date' => $date, 'interview_time' => '10:00'])->assertOk();
        $record = $this->action($record, 'agree', ['text' => 'All requested original arrangements are confirmed'])->assertOk()->json('request');
        $this->assertFalse($record['scheduleChanged']);
        $this->patchJson('/api/employer/applications/'.$this->application->id, ['status' => 'interview', 'interview_date' => $date, 'interview_time' => '11:00'])->assertOk();
        $this->getJson('/api/accommodation-requests/'.$record['id'])->assertJsonPath('request.scheduleChanged', true)->assertJsonPath('request.interview.time', '11:00')->assertJsonPath('request.arrangementInterview.time', '10:00');
        $this->getJson('/api/employer/applications')->assertJsonPath('applicants.0.confirmedArrangements.0.scheduleChanged', true);
        $this->assertSame('interview', $this->application->fresh()->status);
        $this->assertSame(1, AccommodationRequest::count());
    }

    public function test_list_filters_validation_and_stable_pagination(): void
    {
        $first = $this->createRequest();
        $second = $this->createRequest();
        Sanctum::actingAs($this->owner);
        $this->action($second, 'acknowledge')->assertOk();
        $this->getJson('/api/accommodation-requests?status=submitted&query=Support')->assertJsonPath('meta.total', 1)->assertJsonPath('requests.0.id', $first['id']);
        $this->getJson('/api/accommodation-requests?per_page=1')->assertJsonPath('requests.0.id', $second['id']);
        $this->getJson('/api/accommodation-requests?per_page=1&page=2')->assertJsonPath('requests.0.id', $first['id']);
        $this->getJson('/api/accommodation-requests?status=invalid')->assertUnprocessable();
        $this->getJson('/api/accommodation-requests?per_page=1000')->assertUnprocessable();
    }

    public function test_agreement_edits_reset_confirmation_and_profile_consent_is_independent(): void
    {
        $record = $this->createRequest(['details' => 'Original requested support']);
        Sanctum::actingAs($this->owner);
        $record = $this->action($record, 'agree', ['text' => 'Original requested support is confirmed'])->assertOk()->json('request');
        Sanctum::actingAs($this->applicant);
        $this->patchJson('/api/applicant/applications/'.$this->application->id, ['share_accommodations' => false])->assertOk();
        $record = $this->action($record, 'edit', ['share_request' => true, 'categories' => ['Accessible restroom'], 'stage' => 'interview', 'details' => 'Changed request requiring new confirmation'])->assertOk()->assertJsonPath('request.status', 'submitted')->assertJsonPath('request.arrangement', null)->json('request');
        $this->getJson('/api/applicant/applications')->assertJsonPath('applications.0.confirmedArrangements', []);
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/accommodation-requests/'.$record['id'])->assertOk()->assertSee('Changed request requiring new confirmation');
        $this->getJson('/api/accommodation-requests/'.$record['id'].'/events')->assertSee('Original requested support is confirmed');
    }

    public function test_requests_never_change_recommendation_scores_or_public_job_access(): void
    {
        $this->applicant->applicantProfile()->create(['skills' => ['Excel'], 'location' => 'Manila', 'preferred_work_mode' => 'Remote']);
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.match.score', 100);
        $record = $this->createRequest(['categories' => ['Other practical arrangement']]);
        Sanctum::actingAs($this->owner);
        $this->action($record, 'unable', ['text' => 'Cannot currently provide this particular arrangement'])->assertOk();
        Sanctum::actingAs($this->applicant);
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.match.score', 100);
        $this->getJson('/api/jobs/'.$this->application->job_listing_id)->assertOk();
        $this->getJson('/api/applicant/applications')->assertJsonPath('applications.0.statusCode', 'submitted');
    }

    public function test_suspension_is_rechecked_and_notification_failure_rolls_back_private_writes(): void
    {
        $this->applicant->update(['status' => 'suspended']);
        $this->postJson('/api/accommodation-requests', $this->submission())->assertForbidden();
        $this->applicant->update(['status' => 'active']);
        Sanctum::actingAs($this->applicant->fresh());
        $event = 'eloquent.creating: '.UserNotification::class;
        Event::listen($event, fn () => throw new \RuntimeException('Simulated notification failure'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson('/api/accommodation-requests', $this->submission());
            $this->fail('Expected a notification failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated notification failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame(0, AccommodationRequest::count());
        $this->assertSame(0, DB::table('accommodation_request_events')->count());
    }

    public function test_existing_application_pages_remain_available_before_phase_four_migration(): void
    {
        Schema::partialMock()->shouldReceive('hasTable')->with('accommodation_requests')->andReturn(false);
        Schema::shouldReceive('hasTable')->with('resume_application_shares')->andReturn(true);
        $this->getJson('/api/applicant/applications')->assertOk()->assertJsonPath('applications.0.confirmedArrangements', []);
        $this->getJson('/api/accommodation-requests')->assertStatus(503);
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/employer/applications')->assertOk()->assertJsonPath('applicants.0.confirmedArrangements', []);
    }
}
