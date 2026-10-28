<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AdminAuditLog;
use App\Models\Application;
use App\Models\Employer;
use App\Models\JobListing;
use App\Models\User;
use App\Models\UserReport;
use App\Services\AdminDashboardService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** Reference implementation from the original overview, independent of the new aggregates. */
    private function legacyStatistics(): array
    {
        return [
            'jobseekers' => User::where('role', 'applicant')->count(),
            'employers' => User::where('role', 'employer')->count(),
            'verifiedEmployers' => Employer::where('verification_status', 'verified')->count(),
            'pendingEmployers' => Employer::where('verification_status', 'pending')->count(),
            'rejectedEmployers' => Employer::where('verification_status', 'rejected')->count(),
            'jobListings' => JobListing::count(), 'activeApprovedJobs' => JobListing::publiclyVisible()->count(),
            'pendingJobs' => JobListing::where('moderation_status', 'pending')->count(),
            'applications' => Application::count(), 'pendingReports' => UserReport::where('status', 'pending')->count(),
            'suspendedAccounts' => User::where('status', 'suspended')->count(),
        ];
    }

    private function fixture(): User
    {
        $this->travelTo(now()->startOfSecond()); // Exercise deterministic ID tie-breaks.
        $admin = User::factory()->create(['role' => 'admin']);
        $accommodation = Accommodation::create(['slug' => 'synthetic-access', 'label' => 'Synthetic access']);
        $visible = null;
        foreach ([
            ['active', 'employer', 'verified'], ['suspended', 'employer', 'verified'],
            ['inactive', 'employer', 'verified'], ['pending', 'employer', 'verified'],
            ['active', 'employer', 'pending'], ['active', 'employer', 'rejected'],
            ['active', 'applicant', 'verified'], ['active', 'admin', 'verified'],
        ] as [$status, $role, $verification]) {
            $owner = User::factory()->create(['role' => $role, 'status' => $status]);
            $company = Employer::create(['user_id' => $owner->id, 'company_name' => 'Synthetic company '.$owner->id,
                'verification_status' => $verification]);
            foreach ([['open', 'approved'], ['closed', 'approved'], ['open', 'pending'], ['open', 'rejected'], ['open', 'hidden']] as [$availability, $moderation]) {
                $job = $company->jobListings()->create(['title' => 'Synthetic role', 'description' => 'Synthetic description',
                    'location' => 'Synthetic location', 'employment_type' => 'Contract', 'work_mode' => 'Remote',
                    'status' => $availability, 'moderation_status' => $moderation]);
                $job->accommodations()->attach($accommodation);
                $visible ??= $job;
            }
        }
        // More than three pending companies proves the summary never expands the preview.
        foreach (range(1, 4) as $i) {
            $owner = User::factory()->create(['role' => 'employer']);
            Employer::create(['user_id' => $owner->id, 'company_name' => 'Synthetic pending '.$i]);
        }
        foreach (['active', 'suspended', 'inactive', 'pending'] as $status) {
            $applicant = User::factory()->create(['role' => 'applicant', 'status' => $status]);
            Application::create(['user_id' => $applicant->id, 'job_listing_id' => $visible->id, 'status' => 'submitted']);
        }
        foreach (['pending', 'pending', 'pending', 'pending', 'under_review', 'resolved', 'dismissed'] as $status) {
            UserReport::create(['reporter_id' => $applicant->id, 'target_type' => 'platform', 'category' => 'platform_problem',
                'description' => 'Synthetic report', 'status' => $status, 'reviewed_by' => $admin->id]);
            AdminAuditLog::record($admin, 'report.updated', 'report', 1, null, $status, 'Synthetic audit');
        }
        // Queue ordering uses created_at then ID, whereas audit ordering uses ID alone.
        Employer::where('verification_status', 'pending')->firstOrFail()->update(['created_at' => now()->addDay()]);
        JobListing::where('moderation_status', 'pending')->firstOrFail()->update(['created_at' => now()->addDay()]);
        UserReport::where('status', 'pending')->firstOrFail()->update(['created_at' => now()->addDay()]);
        AdminAuditLog::firstOrFail()->update(['created_at' => now()->addDay()]);

        return $admin;
    }

    public static function datasets(): array
    {
        return ['empty' => [false], 'mixed roles, statuses and visibility' => [true]];
    }

    #[DataProvider('datasets')]
    public function test_statistics_match_all_legacy_fields_and_integer_types_with_six_queries(bool $populated): void
    {
        if ($populated) {
            $this->fixture();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $expected = $this->legacyStatistics();
        $this->assertCount(11, DB::getQueryLog());
        DB::flushQueryLog();
        $actual = app(AdminDashboardService::class)->statistics();
        $this->assertCount(6, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($expected, $actual);
        foreach ($actual as $count) {
            $this->assertIsInt($count);
        }
        $this->assertSame($populated ? 1 : 0, $actual['activeApprovedJobs']);
        if (! $populated) {
            $this->assertSame(array_fill_keys(array_keys($expected), 0), $actual);
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/overview')->assertOk()->assertExactJson(['stats' => $expected]);
    }

    public function test_dashboard_preserves_first_page_card_fields_and_reduces_business_queries_from_26_to_10(): void
    {
        Sanctum::actingAs($this->fixture());
        DB::enableQueryLog();
        DB::flushQueryLog();
        $expected = ['stats' => $this->legacyStatistics()];
        $cardFields = ['employers' => ['id', 'companyName'], 'jobs' => ['id', 'title'],
            'reports' => ['id', 'category'], 'audit' => ['id', 'action', 'targetType', 'targetId']];
        foreach (['employers' => 'employers', 'jobs' => 'jobs', 'reports' => 'reports', 'audit' => 'audit-logs'] as $key => $path) {
            $status = $key === 'audit' ? '' : '&status=pending';
            $response = $this->getJson('/api/admin/'.$path.'?page=1&per_page=3'.$status)->assertOk();
            $expected[$key] = array_map(fn ($item) => array_intersect_key($item, array_flip($cardFields[$key])),
                $response->json($key === 'audit' ? 'logs' : $key));
            $this->assertCount(3, $expected[$key]);
        }
        $legacyQueries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $response = $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $dashboardQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($expected, $response->json());
        $this->assertSame(26, $legacyQueries, 'Synthetic business queries only; session/cache stores are arrays in these tests.');
        $this->assertSame(10, $dashboardQueries);
        $this->assertSame(16, $legacyQueries - $dashboardQueries);
        // Caller parameters cannot turn the fixed preview into a larger or filtered export.
        $this->getJson('/api/admin/dashboard?page=2&per_page=50&status=verified')->assertExactJson($expected);
        foreach (['employers', 'jobs', 'reports', 'audit-logs'] as $path) {
            $status = $path === 'audit-logs' ? '' : '&status=pending';
            $this->getJson('/api/admin/'.$path.'?page=2&per_page=2'.$status)->assertOk()
                ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', 2);
            $this->getJson('/api/admin/'.$path.'?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        }
    }

    public function test_empty_dashboard_has_complete_zero_statistics_and_empty_previews(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/admin/dashboard')->assertOk()->assertExactJson([
            'stats' => $this->legacyStatistics(), 'employers' => [], 'jobs' => [], 'reports' => [], 'audit' => [],
        ]);
    }

    public function test_dashboard_requires_an_active_admin_and_retains_api_throttling(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        foreach ([['applicant', 'active'], ['employer', 'active'], ['admin', 'suspended'], ['admin', 'inactive'], ['admin', 'pending']] as [$role, $status]) {
            Sanctum::actingAs(User::factory()->create(['role' => $role, 'status' => $status]));
            $this->getJson('/api/admin/dashboard')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        RateLimiter::for('api', fn () => Limit::perMinute(1)->by('synthetic-dashboard-limit'));
        $this->getJson('/api/admin/dashboard')->assertOk();
        $this->getJson('/api/admin/dashboard')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_dashboard_failure_returns_an_error_instead_of_partial_or_fabricated_statistics(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->mock(AdminDashboardService::class, function ($mock) {
            $mock->shouldReceive('dashboard')->once()->andThrow(new \RuntimeException('synthetic-private-database-error'));
        });
        $this->getJson('/api/admin/dashboard')->assertStatus(500)
            ->assertExactJson(['message' => 'The service is temporarily unavailable. Please try again later.']);
    }
}
