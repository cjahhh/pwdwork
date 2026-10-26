<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['trustedproxy.proxies' => []]);
    }

    public static function roles(): array
    {
        return [['applicant'], ['employer'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_each_role_can_log_in_restore_session_and_log_out_without_changing_records(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $before = $user->refresh()->getAttributes();
        $this->withSession(['before_login' => true]);
        $oldSession = session()->getId();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', $role)->assertJsonMissingPath('token')
            ->assertJsonMissingPath('user.password');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSession, session()->getId());
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest('web');
        // Laravel intentionally rotates remember_token on logout.
        $this->assertSame($before['password'], $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unknown_active_and_suspended_emails_have_identical_wrong_password_errors(): void
    {
        $active = User::factory()->create();
        $suspended = User::factory()->create(['status' => 'suspended']);
        $responses = [];
        foreach ([$active->email, $suspended->email, 'unknown@example.test'] as $email) {
            $response = $this->postJson('/api/login', ['email' => $email, 'password' => 'wrong-password'])
                ->assertUnprocessable()->assertJsonValidationErrors('email')
                ->assertHeader('Cache-Control', 'no-store, private');
            $responses[] = $response->json();
            $this->assertGuest('web');
        }
        $this->assertSame($responses[0], $responses[1]);
        $this->assertSame($responses[0], $responses[2]);
    }

    public function test_unknown_email_performs_configured_password_hashing_work(): void
    {
        $hasher = Hash::driver();
        Hash::shouldReceive('make')->once()->with('wrong-password')
            ->andReturnUsing(fn ($password) => $hasher->make($password));
        $this->postJson('/api/login', ['email' => 'unknown@example.test', 'password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertGuest('web');
    }

    #[DataProvider('roles')]
    public function test_suspended_accounts_remain_blocked_with_correct_credentials(string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'suspended']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->assertGuest('web');
        $this->assertSame('suspended', $user->fresh()->status);
    }

    public function test_existing_and_unknown_accounts_have_identical_distributed_lockouts_and_recover(): void
    {
        $user = User::factory()->create();
        $before = $user->refresh()->getAttributes();
        $lockouts = [];
        foreach ([$user->email, 'unknown@example.test'] as $offset => $email) {
            foreach (range(1, 10) as $i) {
                $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($i + $offset * 20)])
                    ->postJson('/api/login', ['email' => $email, 'password' => 'wrong-password'])->assertUnprocessable();
            }
            $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($offset + 1)])
                ->postJson('/api/login', ['email' => $email, 'password' => 'password'])
                ->assertStatus(429)->assertHeader('Retry-After')->assertHeader('X-RateLimit-Remaining', '0');
            $lockouts[] = $response->json();
        }
        $this->assertSame($lockouts[0], $lockouts[1]);
        $this->assertGuest('web');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->travel(61)->seconds();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_successful_login_does_not_reset_the_account_budget(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 9) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnprocessable();
        }
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest('web');
    }

    public function test_database_cache_shares_account_attempts_across_independent_limiter_instances(): void
    {
        $user = User::factory()->create();
        $definitions = [
            'api' => RateLimiter::limiter('api'),
            'login-account' => RateLimiter::limiter('login-account'),
        ];
        foreach (range(1, 11) as $i) {
            // Simulate separate workers using the production cache driver, on SQLite :memory: only.
            $limiter = new CacheRateLimiter(Cache::store('database'));
            foreach ($definitions as $name => $definition) {
                $limiter->for($name, $definition);
            }
            RateLimiter::swap($limiter);
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertStatus($i <= 10 ? 422 : 429);
        }
        $this->assertGuest('web');
    }
}
