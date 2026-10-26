<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Employer;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InterviewNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $applicant;

    private User $owner;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise the real database queue configuration, without a worker or queue fake.
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite']);
        $this->owner = User::factory()->create(['role' => 'employer', 'status' => 'active']);
        $this->applicant = User::factory()->create(['role' => 'applicant', 'status' => 'active']);
        $company = Employer::create(['user_id' => $this->owner->id, 'company_name' => 'Test Company', 'verification_status' => 'verified']);
        $job = $company->jobListings()->create([
            'title' => 'Support role', 'description' => 'Customer support', 'employment_type' => 'Full Time',
            'work_mode' => 'Remote', 'location' => 'Manila', 'status' => 'open', 'moderation_status' => 'approved',
        ]);
        $this->application = Application::create([
            'user_id' => $this->applicant->id, 'job_listing_id' => $job->id, 'status' => 'submitted',
            'cover_letter' => 'PRIVATE_COVER_LETTER', 'share_accommodations' => false,
        ]);
    }

    private function schedule(array $overrides = []): array
    {
        return array_replace([
            'status' => 'interview', 'interview_date' => now()->addDays(3)->toDateString(),
            'interview_time' => '09:00', 'interview_notes' => 'PRIVATE_INTERVIEW_NOTES',
        ], $overrides);
    }

    private function scheduleInterview(array $overrides = [])
    {
        return $this->patchJson('/api/employer/applications/'.$this->application->id, $this->schedule($overrides));
    }

    public function test_scheduling_creates_an_immediate_private_notification_for_the_applicant_without_a_worker(): void
    {
        $this->applicant->applicantProfile()->create(['summary' => 'PRIVATE_PROFILE', 'pwd_id_number' => 'PRIVATE_PWD_ID']);
        Sanctum::actingAs($this->owner);
        $this->scheduleInterview(['user_id' => $this->owner->id])->assertOk();

        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $this->applicant->id, 'title' => 'Interview scheduled',
            'action_url' => '/applicant/applications', 'read_at' => null,
        ]);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);

        Sanctum::actingAs($this->applicant);
        $notice = UserNotification::sole();
        $response = $this->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $notice->id)
            ->assertJsonPath('notifications.0.title', 'Interview scheduled')
            ->assertJsonPath('notifications.0.actionUrl', '/applicant/applications')
            ->assertJsonPath('notifications.0.readAt', null)
            ->assertDontSee('PRIVATE_INTERVIEW_NOTES')->assertDontSee('PRIVATE_COVER_LETTER')
            ->assertDontSee('PRIVATE_PROFILE')->assertDontSee('PRIVATE_PWD_ID');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->getJson('/api/applicant/applications')->assertOk()
            ->assertJsonPath('applications.0.statusCode', 'interview')
            ->assertJsonPath('applications.0.interviewDate', $this->schedule()['interview_date'])
            ->assertJsonPath('applications.0.interviewNotes', 'PRIVATE_INTERVIEW_NOTES')
            ->assertJsonPath('applications.0.shareAccommodations', false);
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/notifications/'.$notice->id.'/read')->assertOk();
        $readAt = $notice->fresh()->read_at;
        $this->assertNotNull($readAt);
        $this->travel(1)->minutes();
        $this->patchJson('/api/notifications/'.$notice->id.'/read')->assertOk();
        $this->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('notifications.0.readAt', $readAt->toIso8601String());
    }

    public function test_other_users_including_the_employer_and_admin_cannot_retrieve_or_read_the_notice(): void
    {
        Sanctum::actingAs($this->owner);
        $this->scheduleInterview()->assertOk();
        $notice = UserNotification::sole();
        foreach ([$this->owner, User::factory()->create(['role' => 'applicant']), User::factory()->create(['role' => 'admin'])] as $outsider) {
            Sanctum::actingAs($outsider);
            $this->getJson('/api/notifications?user_id='.$this->applicant->id)->assertOk()
                ->assertJsonCount(0, 'notifications')->assertJsonPath('meta.total', 0);
            $this->patchJson('/api/notifications/'.$notice->id.'/read')->assertForbidden();
            $this->assertNull($notice->fresh()->read_at);
        }
    }

    public function test_guests_cannot_schedule_or_access_notifications(): void
    {
        $this->scheduleInterview()->assertUnauthorized();
        $notice = $this->applicant->userNotifications()->create(['title' => 'Private notice']);
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->patchJson('/api/notifications/'.$notice->id.'/read')->assertUnauthorized();
        $this->assertSame('submitted', $this->application->fresh()->status);
    }

    public function test_inactive_recipients_cannot_retrieve_or_read_notifications(): void
    {
        Sanctum::actingAs($this->owner);
        $this->scheduleInterview()->assertOk();
        $notice = UserNotification::sole();
        $this->applicant->update(['status' => 'suspended']);
        Sanctum::actingAs($this->applicant->fresh());
        $this->getJson('/api/notifications')->assertForbidden();
        $this->patchJson('/api/notifications/'.$notice->id.'/read')->assertForbidden();
        $this->assertNull($notice->fresh()->read_at);
    }

    public function test_unauthorized_roles_and_other_employers_cannot_schedule_or_create_notices(): void
    {
        $otherOwner = User::factory()->create(['role' => 'employer']);
        Employer::create(['user_id' => $otherOwner->id, 'company_name' => 'Other Company']);
        foreach ([$otherOwner, $this->applicant, User::factory()->create(['role' => 'admin'])] as $user) {
            Sanctum::actingAs($user);
            $this->scheduleInterview()->assertForbidden();
        }
        $this->owner->update(['status' => 'suspended']);
        Sanctum::actingAs($this->owner->fresh());
        $this->scheduleInterview()->assertForbidden();
        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertSame('submitted', $this->application->fresh()->status);
    }

    public function test_invalid_schedules_create_no_notification_or_schedule(): void
    {
        Sanctum::actingAs($this->owner);
        foreach ([['interview_date' => null], ['interview_time' => null], ['interview_time' => '25:00'], ['interview_date' => now()->subDay()->toDateString()]] as $invalid) {
            $this->scheduleInterview($invalid)->assertUnprocessable();
        }
        $this->assertDatabaseCount('user_notifications', 0);
        $this->assertSame('submitted', $this->application->fresh()->status);
    }

    public function test_retries_do_not_duplicate_notices_including_mysql_time_format(): void
    {
        Sanctum::actingAs($this->owner);
        $this->scheduleInterview()->assertOk();
        $this->scheduleInterview()->assertOk();
        // MySQL TIME columns return seconds, whereas the API accepts H:i.
        $this->application->refresh()->update(['interview_time' => '09:00:00']);
        $this->scheduleInterview()->assertOk();
        $this->assertDatabaseCount('user_notifications', 1);
    }

    public function test_rescheduling_and_note_changes_notify_once_per_change_with_newest_first(): void
    {
        Sanctum::actingAs($this->owner);
        $this->scheduleInterview()->assertOk();
        $updated = [];
        foreach ([['interview_date' => now()->addDays(4)->toDateString()], ['interview_time' => '11:00'], ['interview_notes' => 'PRIVATE_UPDATED_NOTES']] as $change) {
            $updated = array_replace($updated, $change);
            $this->scheduleInterview($updated)->assertOk();
            $this->scheduleInterview($updated)->assertOk();
        }
        $this->assertDatabaseCount('user_notifications', 4);
        $this->assertDatabaseCount('jobs', 0);
        Sanctum::actingAs($this->applicant);
        $this->getJson('/api/notifications?per_page=1')->assertOk()->assertJsonPath('meta.total', 4)
            ->assertJsonPath('notifications.0.id', UserNotification::latest('id')->firstOrFail()->id)
            ->assertJsonPath('notifications.0.title', 'Interview updated')->assertDontSee('PRIVATE_UPDATED_NOTES');
        $this->getJson('/api/notifications?per_page=1&page=4')->assertOk()
            ->assertJsonPath('notifications.0.title', 'Interview scheduled');
    }

    public function test_non_interview_updates_do_not_create_interview_notifications(): void
    {
        Sanctum::actingAs($this->owner);
        foreach (['reviewing', 'shortlisted', 'hired', 'rejected'] as $status) {
            $this->scheduleInterview(['status' => $status])->assertOk();
        }
        $this->assertDatabaseCount('user_notifications', 0);
        $this->scheduleInterview()->assertOk();
        $this->scheduleInterview(['status' => 'reviewing'])->assertOk();
        $this->assertDatabaseCount('user_notifications', 1);
        $this->assertNull($this->application->fresh()->interview_date);
        $this->scheduleInterview()->assertOk();
        $this->assertDatabaseCount('user_notifications', 2);
    }

    public function test_notification_failure_rolls_back_the_interview_update(): void
    {
        Sanctum::actingAs($this->owner);
        $event = 'eloquent.creating: '.UserNotification::class;
        Event::listen($event, fn () => throw new \RuntimeException('Simulated notification failure'));
        $this->withoutExceptionHandling();
        try {
            $this->scheduleInterview();
            $this->fail('Expected a notification failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated notification failure', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('user_notifications', 0);
        $application = $this->application->fresh();
        $this->assertSame('submitted', $application->status);
        $this->assertNull($application->interview_date);
        $this->assertNull($application->interview_time);
        $this->assertNull($application->interview_notes);
    }
}
