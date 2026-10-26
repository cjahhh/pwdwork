<?php

namespace Tests\Feature;

use App\Jobs\SendEmailVerification;
use App\Models\User;
use App\Services\EmailVerification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['email-verification.enabled' => true, 'email-verification.connection' => 'database']);
        Queue::fake([SendEmailVerification::class]);
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function enrolled(string $role = 'applicant'): User
    {
        $user = User::factory()->create(['role' => $role])->forceFill([
            'email_verified_at' => null, 'email_verification_required_at' => now(), 'email_verification_version' => 1,
        ]);
        $user->saveOrFail();

        return $user->refresh();
    }

    public static function publicRoles(): array
    {
        return [['applicant'], ['employer']];
    }

    #[DataProvider('publicRoles')]
    public function test_registration_enrolls_and_sends_once_without_trusting_request_verification_fields(string $role): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'New account', 'email' => 'new@example.test', 'password' => 'password',
            'role' => $role, 'company_name' => 'Inclusive Company',
            'email_verified_at' => now()->toDateTimeString(), 'email_ownership_verified_at' => now()->toDateTimeString(),
            'email_verification_required_at' => null, 'email_verification_version' => 999,
        ])->assertCreated()->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.email_verification_required', true)->assertJsonPath('user.role', $role);
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->email_ownership_verified_at);
        $this->assertSame(1, $user->email_verification_version);
        $this->assertStringNotContainsString('signature', $response->getContent());
        Queue::assertPushed(SendEmailVerification::class, 1);
        Queue::assertPushed(SendEmailVerification::class, fn ($job) => $job->userId === $user->id && $job->version === 1 && $job->afterCommit === true);
        if ($role === 'employer') {
            $this->assertSame('pending', $user->employer->verification_status);
        }
        $this->getJson('/api/profile')->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->getJson('/api/jobs')->assertOk();
        $this->getJson('/api/user')->assertOk();
        $this->postJson('/api/logout')->assertOk();
    }

    public function test_successful_confirmation_is_explicit_session_bound_and_idempotent(): void
    {
        Event::fake([Verified::class]);
        $user = $this->enrolled();
        $proof = app(EmailVerification::class)->proof($user);
        $before = $user->getAttributes();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $oldSession = session()->getId();
        $this->getJson('/api/email/verification/confirm')->assertStatus(405);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->postJson('/api/email/verification/confirm', ['proof' => $proof])->assertOk();
        $this->assertNotSame($oldSession, session()->getId());
        $verifiedAt = $user->fresh()->email_ownership_verified_at;
        $this->assertNotNull($verifiedAt);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame('applicant', $user->fresh()->role);
        $this->postJson('/api/email/verification/confirm', ['proof' => $proof])->assertOk();
        $this->assertTrue($verifiedAt->equalTo($user->fresh()->email_ownership_verified_at));
        Event::assertDispatchedTimes(Verified::class, 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertJsonPath('user.email_verified', true)->assertJsonPath('user.email_verification_required', false);
        $this->getJson('/api/profile')->assertOk();
    }

    public function test_expired_link_does_not_verify_or_change_account(): void
    {
        $user = $this->enrolled();
        $proof = app(EmailVerification::class)->proof($user);
        $this->travel(61)->minutes();
        $before = $user->getAttributes();
        $this->actingAs($user, 'web')->postJson('/api/email/verification/confirm', ['proof' => $proof])
            ->assertUnprocessable()->assertExactJson(['message' => 'This verification link is invalid or expired. Request a new email.']);
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_other_users_cannot_consume_a_link_and_unauthenticated_resend_is_rejected(): void
    {
        $owner = $this->enrolled();
        $other = $this->enrolled();
        $proof = app(EmailVerification::class)->proof($owner);
        $this->postJson('/api/email/verification/confirm', ['proof' => $proof])->assertUnauthorized();
        $this->postJson('/api/email/verification/resend')->assertUnauthorized();
        $this->actingAs($other, 'web')->postJson('/api/email/verification/confirm', ['proof' => $proof])->assertUnprocessable();
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_modified_malformed_external_and_query_only_proofs_are_rejected_without_echoing_input(): void
    {
        $user = $this->enrolled();
        $this->actingAs($user, 'web');
        $proof = app(EmailVerification::class)->proof($user);
        foreach ([
            str_replace('version=1', 'version=9', $proof), 'https://evil.example'.$proof,
            $proof.'&extra=PRIVATE_SECRET', $proof.'&user='.$user->id,
            str_replace('/confirm?', '/confirm/other?', $proof), ['PRIVATE_SECRET'],
            str_repeat('PRIVATE_SECRET', 200), '', "\0PRIVATE_SECRET",
        ] as $invalid) {
            $this->postJson('/api/email/verification/confirm', ['proof' => $invalid])
                ->assertUnprocessable()->assertDontSee('PRIVATE_SECRET')->assertDontSee('signature');
        }
        // A supplied proof in the query string is never accepted as a request-body proof.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        $this->postJson('/api/email/verification/confirm?'.http_build_query(['proof' => $proof]), [])->assertUnprocessable();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_uses_account_cooldown_across_ips_and_invalidates_the_previous_link(): void
    {
        $user = $this->enrolled();
        $old = app(EmailVerification::class)->proof($user);
        $this->actingAs($user, 'web')->postJson('/api/email/verification/resend', ['email' => 'attacker@example.test'])->assertStatus(202);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/email/verification/resend')
            ->assertStatus(429)->assertHeader('Retry-After');
        Queue::assertPushed(SendEmailVerification::class, 1);
        $this->postJson('/api/email/verification/confirm', ['proof' => $old])->assertUnprocessable();
        $this->postJson('/api/email/verification/confirm', ['proof' => app(EmailVerification::class)->proof($user->fresh())])->assertOk();
    }

    public function test_hourly_account_limit_and_nonblocking_send_lock_prevent_duplicate_sends(): void
    {
        $user = $this->enrolled();
        $service = app(EmailVerification::class);
        $lock = Cache::lock('email-verification:send:'.$user->id, 15);
        $this->assertTrue($lock->get());
        $this->assertSame(1, $service->request($user));
        Queue::assertNothingPushed();
        $lock->release();
        foreach (range(1, 5) as $i) {
            $this->assertSame(0, $service->request($user->fresh()));
            $this->travel(61)->seconds();
        }
        $this->assertGreaterThan(0, $service->request($user->fresh()));
        Queue::assertPushed(SendEmailVerification::class, 5);
        $this->travel(3601)->seconds();
        $this->assertSame(0, $service->request($user->fresh()));
    }

    public function test_ip_limit_applies_across_different_authenticated_accounts(): void
    {
        foreach (range(1, 7) as $i) {
            $user = $this->enrolled();
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'web')->postJson('/api/email/verification/resend')->assertStatus($i <= 6 ? 202 : 429);
        }
        Queue::assertPushed(SendEmailVerification::class, 6);
    }

    public function test_disabling_enrollment_preserves_existing_enforcement_and_pending_link_recovery(): void
    {
        $user = $this->enrolled();
        config(['email-verification.enabled' => false]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->getJson('/api/profile')->assertForbidden();
        $this->postJson('/api/email/verification/resend')->assertStatus(202);
        $this->postJson('/api/email/verification/confirm', ['proof' => app(EmailVerification::class)->proof($user->fresh())])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/profile')->assertOk();
    }

    public function test_legacy_accounts_keep_access_without_an_ownership_claim_or_record_changes(): void
    {
        foreach (['applicant', 'employer', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role])->refresh();
            if ($role === 'employer') {
                $user->employer()->create(['company_name' => 'Existing Company', 'verification_status' => 'verified']);
            }
            $before = $user->getAttributes();
            $this->app['auth']->forgetGuards();
            $this->actingAs($user, 'web')->getJson('/api/user')->assertOk()
                ->assertJsonPath('user.email_verified', false)->assertJsonPath('user.email_verification_required', false);
            $path = match ($role) {
                'applicant' => '/api/applicant/applications', 'employer' => '/api/employer/jobs', default => '/api/admin/overview'
            };
            $this->getJson($path)->assertOk();
            $this->assertSame($before, $user->fresh()->getAttributes());
        }
    }

    public function test_legacy_user_can_voluntarily_verify_without_becoming_required_or_changing_roles(): void
    {
        $user = User::factory()->admin()->create()->refresh();
        $this->actingAs($user, 'web')->postJson('/api/email/verification/resend')->assertStatus(202);
        $this->postJson('/api/email/verification/confirm', ['proof' => app(EmailVerification::class)->proof($user->fresh())])->assertOk();
        $this->assertNull($user->fresh()->email_verification_required_at);
        $this->assertSame('admin', $user->fresh()->role);
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->getJson('/api/admin/overview')->assertOk();
    }

    public function test_all_sensitive_api_routes_have_the_ownership_guard_and_tokens_cannot_bypass_it(): void
    {
        $exceptions = ['api/user', 'api/logout', 'api/email/verification', 'api/email/verification/resend', 'api/email/verification/confirm'];
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            if (in_array('auth:sanctum', $middleware, true) && ! in_array($route->uri(), $exceptions, true)) {
                $this->assertContains('email.ownership', $middleware, $route->uri());
                $checked++;
            }
        }
        $this->assertGreaterThan(30, $checked);
        $user = $this->enrolled('employer');
        $token = $user->createToken('verification-security-test')->plainTextToken;
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$token);
        foreach ([['get', '/api/employer/applications'], ['post', '/api/employer/jobs'], ['get', '/api/applicant/resumes'], ['get', '/api/admin/overview']] as [$method, $path]) {
            $this->{$method.'Json'}($path)->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        }
        $this->getJson('/api/jobs')->assertOk();
    }

    public function test_verification_does_not_approve_employers_or_grant_admin_access_and_suspension_wins(): void
    {
        $user = $this->enrolled('employer');
        $employer = $user->employer()->create(['company_name' => 'Pending Company', 'verification_status' => 'pending']);
        $proof = app(EmailVerification::class)->proof($user);
        $this->actingAs($user, 'web')->postJson('/api/email/verification/confirm', ['proof' => $proof])->assertOk();
        $this->assertSame('pending', $employer->fresh()->verification_status);
        $this->getJson('/api/admin/overview')->assertForbidden();
        $user->forceFill(['status' => 'suspended'])->save();
        $this->postJson('/api/email/verification/resend')->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->postJson('/api/email/verification/confirm', ['proof' => $proof])->assertForbidden();
    }

    public function test_email_change_clears_proven_ownership_and_rejects_a_previous_email_link(): void
    {
        $user = $this->enrolled();
        $old = app(EmailVerification::class)->proof($user);
        $user->markEmailAsVerified();
        $user->email = 'changed@example.test';
        $user->save();
        $this->assertTrue($user->fresh()->requiresEmailVerification());
        $this->assertNull($user->fresh()->email_ownership_verified_at);
        $this->actingAs($user->fresh(), 'web')->postJson('/api/email/verification/confirm', ['proof' => $old])->assertUnprocessable();
    }

    public function test_invalid_delivery_configuration_fails_before_creating_an_enrolled_account(): void
    {
        config(['email-verification.connection' => 'sync']);
        $this->postJson('/api/register', ['name' => 'Example', 'email' => 'new@example.test', 'password' => 'password', 'role' => 'applicant'])->assertStatus(503);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_disabled_feature_does_not_enroll_send_or_claim_verification(): void
    {
        config(['email-verification.enabled' => false, 'email-verification.connection' => 'sync']);
        $this->postJson('/api/register', ['name' => 'Example', 'email' => 'new@example.test', 'password' => 'password', 'role' => 'applicant'])
            ->assertCreated()->assertJsonPath('user.email_verification_required', false)->assertJsonPath('user.email_verified', false);
        $user = User::firstOrFail();
        $this->assertNull($user->email_verification_required_at);
        Queue::assertNothingPushed();
        $this->getJson('/api/profile')->assertOk();
        $this->postJson('/api/email/verification/resend')->assertNotFound();
    }

    public function test_database_cache_preserves_resend_limits_across_independent_limiter_instances(): void
    {
        $user = $this->enrolled();
        $definitions = [];
        foreach (['api', 'verification-resend'] as $name) {
            $definitions[$name] = RateLimiter::limiter($name);
        }
        config(['cache.default' => 'database']);
        foreach (range(1, 2) as $i) {
            $limiter = new CacheRateLimiter(Cache::store('database'));
            foreach ($definitions as $name => $definition) {
                $limiter->for($name, $definition);
            }
            RateLimiter::swap($limiter);
            $this->actingAs($user, 'web')->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
                ->postJson('/api/email/verification/resend')->assertStatus($i === 1 ? 202 : 429);
        }
        Queue::assertPushed(SendEmailVerification::class, 1);
    }

    public function test_dispatch_failure_keeps_the_account_session_and_pending_requirement(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('PRIVATE_QUEUE_CREDENTIALS'));
        $this->postJson('/api/register', ['name' => 'Example', 'email' => 'new@example.test', 'password' => 'password', 'role' => 'applicant'])
            ->assertCreated()->assertJsonPath('user.email_verification_required', true)->assertDontSee('PRIVATE_');
        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue($user->requiresEmailVerification());
        $this->assertDatabaseCount('applicant_profiles', 1);
        $this->getJson('/api/profile')->assertForbidden();
    }

    public function test_password_recovery_does_not_verify_a_pending_account_and_login_stays_available(): void
    {
        $user = $this->enrolled();
        $token = Password::createToken($user);
        $this->postJson('/api/reset-password', [
            'email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertOk();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertTrue($user->fresh()->requiresEmailVerification());
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'new-password'])->assertOk();
        $this->getJson('/api/profile')->assertForbidden()->assertJsonPath('code', 'email_verification_required');
    }

    public function test_first_party_confirmation_and_resend_require_csrf_protection(): void
    {
        $user = $this->enrolled();
        $proof = app(EmailVerification::class)->proof($user);
        $this->actingAs($user, 'web');
        $this->app->instance('env', 'local');
        $this->postJson('/api/email/verification/confirm', ['proof' => $proof])->assertStatus(419)->assertDontSee('signature');
        $this->postJson('/api/email/verification/resend')->assertStatus(419);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Queue::assertNothingPushed();
    }
}
