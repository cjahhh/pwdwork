<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'applicant'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function job(array $fields = []): JobListing
    {
        $employer = Employer::create(['user_id' => $this->user('employer')->id, 'company_name' => 'Company', 'verification_status' => 'verified']);

        return $employer->jobListings()->create(['title' => 'Customer service', 'description' => 'Customer service and Excel', 'location' => 'Manila', 'employment_type' => 'Contract', 'work_mode' => 'Remote', 'status' => 'open', 'moderation_status' => 'approved', ...$fields]);
    }

    public function test_authentication_roles_validation_and_empty_profile(): void
    {
        $this->getJson('/api/applicant/recommendations')->assertUnauthorized();
        Sanctum::actingAs($this->user('employer'));
        $this->getJson('/api/applicant/recommendations')->assertForbidden();
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/applicant/recommendations')->assertForbidden();
        Sanctum::actingAs($this->user());
        $this->getJson('/api/applicant/recommendations')->assertOk()->assertJsonPath('meta.total', 0);
        $this->job();
        $this->getJson('/api/applicant/recommendations')->assertOk()->assertJsonPath('jobs.0.match.score', null);
        $this->getJson('/api/applicant/recommendations?per_page=100')->assertUnprocessable();
        $this->getJson('/api/applicant/recommendations?page=0')->assertUnprocessable();
    }

    public function test_recommendation_saved_state_tracks_existing_saved_jobs_and_is_account_scoped(): void
    {
        $job = $this->job();
        $applicant = $this->user();
        Sanctum::actingAs($applicant);
        $this->getJson('/api/applicant/recommendations')->assertOk()->assertJsonPath('jobs.0.saved', false);
        $this->putJson('/api/applicant/saved-jobs/'.$job->id)->assertOk();
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.saved', true);
        $this->getJson('/api/applicant/saved-jobs')->assertJsonPath('jobs.0.id', $job->id);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.saved', false);
        Sanctum::actingAs($applicant);
        $this->deleteJson('/api/applicant/saved-jobs/'.$job->id)->assertOk();
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.saved', false);
    }

    public function test_calculation_privacy_unknown_support_and_live_updates(): void
    {
        $user = $this->user();
        $profile = $user->applicantProfile()->create(['skills' => ['Excel', 'PHP'], 'location' => 'Manila', 'preferred_work_mode' => 'Remote', 'summary' => 'PRIVATE SUMMARY', 'pwd_id_number' => 'PRIVATE ID']);
        $job = $this->job();
        Sanctum::actingAs($user);
        // (20 skills + 25 mode + 15 location) / 80 = 75.
        $this->getJson('/api/applicant/recommendations')->assertOk()->assertJsonPath('jobs.0.match.score', 75)->assertJsonPath('jobs.0.match.matchingSkills', ['excel'])->assertDontSee('PRIVATE SUMMARY')->assertDontSee('PRIVATE ID');
        $support = Accommodation::create(['label' => 'Screen reader', 'slug' => 'screen-reader']);
        $profile->accommodations()->attach($support);
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.match.score', 75)->assertSee('unknown');
        $job->accommodations()->attach($support);
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.match.score', 75)->assertJsonPath('jobs.0.match.reportedAccommodationMatches', ['Screen reader']);
        $profile->update(['skills' => ['Excel']]);
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.match.score', 100);
        $job->update(['work_mode' => 'Hybrid']);
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.match.score', 69);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/applicant/recommendations?user_id='.$user->id)->assertJsonPath('jobs.0.match.score', null)->assertJsonPath('jobs.0.match.reportedAccommodationMatches', []);
    }

    public function test_all_publication_gates_and_stable_pagination(): void
    {
        $first = $this->job();
        $second = $this->job();
        foreach (['pending', 'hidden', 'rejected'] as $status) {
            $this->job(['moderation_status' => $status]);
        }
        $this->job(['status' => 'closed']);
        $unverified = $this->job();
        $unverified->employer->update(['verification_status' => 'pending']);
        $suspended = $this->job();
        $suspended->employer->user->update(['status' => 'suspended']);
        Sanctum::actingAs($this->user());
        $this->getJson('/api/applicant/recommendations?per_page=1')->assertJsonPath('meta.total', 2)->assertJsonPath('jobs.0.id', $second->id);
        $this->getJson('/api/applicant/recommendations?per_page=1&page=2')->assertJsonPath('jobs.0.id', $first->id);
        $this->getJson('/api/applicant/recommendations?per_page=1')->assertJsonPath('jobs.0.id', $second->id);
    }

    public function test_profile_work_preference_round_trip_and_validation(): void
    {
        Sanctum::actingAs($this->user());
        $this->putJson('/api/profile', ['name' => 'Applicant', 'preferredWorkMode' => 'Remote'])->assertOk()->assertJsonPath('profile.preferredWorkMode', 'Remote');
        $this->putJson('/api/profile', ['name' => 'Applicant'])->assertOk()->assertJsonPath('profile.preferredWorkMode', 'Remote');
        $this->putJson('/api/profile', ['name' => 'Applicant', 'preferredWorkMode' => 'Invalid'])->assertUnprocessable();
        $this->putJson('/api/profile', ['name' => 'Applicant', 'preferredWorkMode' => ''])->assertOk()->assertJsonPath('profile.preferredWorkMode', '');
    }

    public function test_unknown_employer_information_is_neutral_and_sensitive_text_is_not_scored(): void
    {
        $user = $this->user();
        $profile = $user->applicantProfile()->create(['location' => 'Manila', 'preferred_work_mode' => 'Remote']);
        $this->job(['location' => '']);
        Sanctum::actingAs($user);
        // 25 arrangement + 7.5 unknown location, normalized by 40.
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.match.score', 81);
        $profile->update(['summary' => 'Private medical information', 'experience' => 'Sensitive background', 'pwd_id_number' => 'DO NOT SHARE']);
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.match.score', 81)->assertDontSee('Private medical information')->assertDontSee('DO NOT SHARE');
    }

    public function test_skill_normalization_no_invented_matches_and_current_ranking(): void
    {
        $user = $this->user();
        $profile = $user->applicantProfile()->create(['skills' => [' Excel ', 'excel', '']]);
        $relevant = $this->job();
        $other = $this->job(['title' => 'Designer', 'description' => 'Illustration']);
        Sanctum::actingAs($user);
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.id', $relevant->id)->assertJsonPath('jobs.0.match.score', 100)->assertJsonPath('jobs.1.match.score', 0)->assertJsonPath('jobs.1.match.matchingSkills', []);
        $profile->update(['skills' => ['Illustration']]);
        $this->getJson('/api/applicant/recommendations')->assertJsonPath('jobs.0.id', $other->id)->assertJsonPath('jobs.0.match.score', 100);
    }
}
