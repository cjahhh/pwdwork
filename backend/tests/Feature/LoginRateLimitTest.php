<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['trustedproxy.proxies' => []]);
    }

    public function test_account_limit_follows_email_across_client_ips(): void
    {
        // Missing passwords exercise validation without needing a database.
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->postJson('/api/login', ['email' => 'candidate@example.test'])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->postJson('/api/login', ['email' => 'candidate@example.test'])->assertStatus(429)
            ->assertExactJson(['message' => 'Too many attempts. Please wait before trying again.'])
            ->assertHeader('Retry-After')->assertHeader('X-RateLimit-Remaining', '0');
        $this->postJson('/api/login', ['email' => 'other@example.test'])->assertUnprocessable();
    }

    public function test_case_and_whitespace_variants_share_an_account_bucket(): void
    {
        foreach (range(1, 10) as $i) {
            $email = $i % 2 ? 'Candidate@Example.Test' : ' candidate@example.test ';
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->postJson('/api/login', ['email' => $email])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->postJson('/api/login', ['email' => 'CANDIDATE@example.test'])->assertStatus(429);
    }

    public function test_existing_ip_limit_still_blocks_attempts_against_different_emails(): void
    {
        foreach (range(1, 10) as $i) {
            $this->postJson('/api/login', ['email' => 'candidate'.$i.'@example.test'])->assertUnprocessable();
        }

        $this->postJson('/api/login', ['email' => 'another@example.test'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertHeader('X-RateLimit-Limit', '10');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])
            ->postJson('/api/login', ['email' => 'another@example.test'])->assertUnprocessable();
    }

    public function test_account_limit_expires_without_being_extended_by_blocked_requests(): void
    {
        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])
                ->postJson('/api/login', ['email' => 'candidate@example.test'])->assertUnprocessable();
        }
        $this->travel(30)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])
            ->postJson('/api/login', ['email' => 'candidate@example.test'])->assertStatus(429);
        $this->travel(31)->seconds();
        $this->postJson('/api/login', ['email' => 'candidate@example.test'])->assertUnprocessable();
    }

    public function test_account_keys_are_hashed_and_malformed_inputs_are_safe(): void
    {
        $limiter = RateLimiter::limiter('login-account');
        $normal = $limiter(Request::create('/api/login', 'POST', ['email' => 'candidate@example.test']));
        $variant = $limiter(Request::create('/api/login', 'POST', ['email' => ' CANDIDATE@Example.Test ']));
        $accented = $limiter(Request::create('/api/login', 'POST', ['email' => "c\u{00e1}ndidate@example.test"]));
        $this->assertSame($normal->key, $variant->key);
        $this->assertSame($normal->key, $accented->key);
        $this->assertStringNotContainsString('candidate', $normal->key);
        $this->assertStringNotContainsString('@', $normal->key);
        $this->postJson('/api/login', ['email' => ['candidate@example.test'], 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_untrusted_forwarded_headers_cannot_bypass_the_ip_limit(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5']);
        foreach (range(1, 11) as $i) {
            $response = $this->withHeaders([
                'X-Forwarded-For' => '198.51.100.'.$i,
                'Forwarded' => 'for=198.51.100.'.$i.';proto=https',
                'CF-Connecting-IP' => '198.51.100.'.$i,
                'True-Client-IP' => '198.51.100.'.$i,
                'X-Real-IP' => '198.51.100.'.$i,
            ])->postJson('/api/login', ['email' => 'candidate'.$i.'@example.test']);
            $response->assertStatus($i <= 10 ? 422 : 429);
        }
    }

    public function test_explicitly_trusted_proxy_resolves_separate_clients_but_not_separate_account_buckets(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.1']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1']);
        foreach (range(1, 10) as $i) {
            $this->withHeader('X-Forwarded-For', '198.51.100.'.$i)
                ->postJson('/api/login', ['email' => 'candidate@example.test'])->assertUnprocessable();
        }
        $this->withHeader('X-Forwarded-For', '203.0.113.1')
            ->postJson('/api/login', ['email' => 'candidate@example.test'])->assertStatus(429);
        $this->postJson('/api/login', ['email' => 'other@example.test'])->assertUnprocessable();
    }

    public function test_trusted_proxy_ip_limit_uses_the_nearest_untrusted_client_in_a_chain(): void
    {
        config(['trustedproxy.proxies' => ['10.0.0.1']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1']);
        foreach (range(1, 11) as $i) {
            $response = $this->withHeader('X-Forwarded-For', '198.51.100.'.$i.', 203.0.113.5')
                ->postJson('/api/login', ['email' => 'candidate'.$i.'@example.test']);
            $response->assertStatus($i <= 10 ? 422 : 429);
        }
        $this->withHeader('X-Forwarded-For', '203.0.113.6')
            ->postJson('/api/login', ['email' => 'other@example.test'])->assertUnprocessable();
    }
}
