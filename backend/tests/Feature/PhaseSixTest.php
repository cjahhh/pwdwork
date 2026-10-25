<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetLink;
use App\Models\Employer;
use App\Models\User;
use App\Notifications\ModerationDecision;
use App\Support\RuntimeRequirements;
use Database\Seeders\PwdWorkSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseSixTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_and_error_responses_have_safe_headers(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer');
        Sanctum::actingAs(User::factory()->create(['role' => 'applicant']));
        foreach (['/api/user', '/api/profile', '/api/applicant/applications', '/api/applicant/resumes', '/api/notifications'] as $path) {
            $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        }
    }

    public function test_all_api_exceptions_hide_debug_details_but_keep_validation_and_throttle_headers(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/audit-error', fn () => throw new \RuntimeException('SECRET SQL(email,diagnosis) C:/private/.env'))->middleware('api');
        $this->getJson('/api/audit-error')->assertStatus(500)->assertExactJson(['message' => 'The service is temporarily unavailable. Please try again later.'])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/api/jobs/99999')->assertNotFound()->assertExactJson(['message' => 'Resource not found.']);
        $this->getJson('/api/jobs?per_page=1000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        Route::get('/api/audit-limit', fn () => response()->json(['ok' => true]))->middleware(['api', 'throttle:1,1']);
        $this->getJson('/api/audit-limit')->assertOk();
        $this->getJson('/api/audit-limit')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_production_exception_reporting_omits_sensitive_messages_and_context(): void
    {
        $this->app->instance('env', 'production');
        Log::shouldReceive('error')->once()->with('Application exception; inspect protected monitoring and reproduce in staging.', ['exception_type' => \RuntimeException::class]);
        report(new \RuntimeException('SECRET SQL bindings email and private text'));
    }

    public function test_direct_demo_seeder_is_blocked_outside_local_and_testing(): void
    {
        $this->app->instance('env', 'production');
        $before = User::count();
        try {
            (new PwdWorkSeeder)->run();
            $this->fail('Production demo seeder must be blocked.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Demo seeding is permitted only in local or testing environments.', $exception->getMessage());
        }
        $this->assertSame($before, User::count());
    }

    public function test_bcrypt_byte_limit_rejects_oversized_utf8_without_server_errors(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173']);
        $password = str_repeat('é', 37);
        $this->postJson('/api/register', ['name' => 'Example', 'email' => 'bytes@example.test', 'password' => $password, 'role' => 'applicant'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/login', ['email' => 'bytes@example.test', 'password' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/reset-password', ['email' => 'bytes@example.test', 'token' => 'invalid', 'password' => $password, 'password_confirmation' => $password])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_cookie_logout_with_an_incidental_bearer_header_does_not_delete_a_transient_token(): void
    {
        $user = User::factory()->create(['role' => 'applicant']);
        $this->actingAs($user, 'web')->withHeaders(['Authorization' => 'Bearer incidental-value', 'Origin' => 'http://localhost:5173'])->postJson('/api/logout')->assertOk()->assertJsonPath('message', 'Logged out.');
        $this->assertGuest('web');
    }

    public function test_sql_metacharacters_are_values_and_cannot_bypass_publication_or_filters(): void
    {
        $this->getJson('/api/jobs?'.http_build_query(['query' => "' OR 1=1 --"]))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/jobs?'.http_build_query(['types' => ["' OR 1=1 --"]]))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_production_check_is_read_only_fails_unsafe_settings_and_never_prints_secrets(): void
    {
        config(['app.debug' => true, 'app.key' => 'SECRET-INVALID-KEY', 'database.connections.mysql.password' => 'SECRET-PASSWORD']);
        DB::shouldReceive('connection')->never();
        $this->artisan('pwdwork:production-check')->expectsOutputToContain('FAIL')->doesntExpectOutputToContain('SECRET-INVALID-KEY')->doesntExpectOutputToContain('SECRET-PASSWORD')->assertExitCode(1);
    }

    public function test_safe_configuration_is_checked_without_connecting_and_runtime_is_still_enforced(): void
    {
        $this->app->instance('env', 'production');
        config(['app.debug' => false, 'app.url' => 'https://staging.example.test', 'app.frontend_url' => 'https://staging.example.test',
            'session.secure' => true, 'session.http_only' => true, 'session.driver' => 'database', 'session.same_site' => 'lax',
            'cors.allowed_origins' => ['https://staging.example.test'], 'cors.allowed_origins_patterns' => [], 'sanctum.stateful' => ['staging.example.test'], 'trustedproxy.proxies' => [],
            'database.default' => 'mysql', 'database.connections.mysql.database' => 'separate_production', 'database.connections.mysql.username' => 'app_user', 'database.connections.mysql.password' => 'SECRET-TEST-ONLY',
            'cache.default' => 'database', 'queue.default' => 'database', 'queue.password_reset_connection' => 'database',
            'filesystems.resume_disk' => 'local', 'filesystems.disks.local.serve' => false,
            'mail.default' => 'smtp', 'mail.password_reset_mailer' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'operator@example.test', 'mail.from.name' => 'Test operator',
            'logging.default' => 'stderr', 'logging.channels.stderr.level' => 'warning']);
        DB::shouldReceive('connection')->never();
        try {
            $patched = RuntimeRequirements::patchedPhp(PHP_VERSION);
            $this->artisan('pwdwork:production-check')->doesntExpectOutputToContain('SECRET-TEST-ONLY')->assertExitCode($patched ? 0 : 1);
        } finally {
            // RefreshDatabase rolls back its own SQLite transaction during teardown.
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_api_group_has_a_shared_rate_limit(): void
    {
        RateLimiter::for('api', fn () => Limit::perMinute(1)->by('phase-six-api-test'));
        $this->getJson('/api/health')->assertOk();
        $this->getJson('/api/health')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_public_listing_queries_stay_bounded_as_result_count_grows(): void
    {
        $owner = User::factory()->create(['role' => 'employer']);
        $company = Employer::create(['user_id' => $owner->id, 'company_name' => 'Query Test', 'verification_status' => 'verified']);
        $fields = ['title' => 'Support', 'description' => 'Customer support', 'employment_type' => 'Contract', 'work_mode' => 'Remote', 'location' => 'Manila', 'status' => 'open', 'moderation_status' => 'approved'];
        $company->jobListings()->create($fields);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/jobs')->assertOk()->assertJsonPath('meta.total', 1);
        $one = count(DB::getQueryLog());
        foreach (range(1, 11) as $i) {
            $company->jobListings()->create($fields);
        }
        DB::flushQueryLog();
        $this->getJson('/api/jobs')->assertOk()->assertJsonPath('meta.total', 12);
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($one, $many, 'Adding rows must not add per-row relationship queries.');
        $this->assertLessThanOrEqual(8, $many);
    }

    public function test_api_origin_root_does_not_publish_framework_or_php_versions(): void
    {
        $this->get('/')->assertOk()->assertExactJson(['app' => 'PWDWork', 'status' => 'ok']);
    }

    public function test_php_security_release_floors_reject_unpatched_and_unreviewed_branches(): void
    {
        foreach (['8.2.12', '8.2.34', '8.3.35', '8.4.26', '8.5.11', '9.0.0'] as $version) {
            $this->assertFalse(RuntimeRequirements::patchedPhp($version));
        }
        foreach (['8.2.35', '8.3.36', '8.4.27', '8.5.12'] as $version) {
            $this->assertTrue(RuntimeRequirements::patchedPhp($version));
        }
    }

    public function test_private_mail_queue_commands_are_encrypted_and_can_be_read_by_the_worker(): void
    {
        $user = User::factory()->create(['role' => 'applicant']);
        $notification = new ModerationDecision('Decision', 'PRIVATE-REVIEW-REASON', '/notifications');
        $jobs = [new SendPasswordResetLink('private-email@example.test'), new SendQueuedNotifications($user, $notification)];
        foreach ($jobs as $job) {
            $id = app('queue')->connection('database')->push($job, '', 'password-resets');
            $json = DB::table('jobs')->where('id', $id)->value('payload');
            $this->assertStringNotContainsString('private-email@example.test', $json);
            $this->assertStringNotContainsString('PRIVATE-REVIEW-REASON', $json);
            $data = json_decode($json, true);
            $serialized = app('encrypter')->decrypt($data['data']['command']);
            $this->assertStringContainsString(get_class($job), $serialized);
        }
    }
}
