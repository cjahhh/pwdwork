<?php

namespace Tests\Feature;

use App\Jobs\SendEmailVerification;
use App\Models\User;
use App\Services\EmailVerification;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'email-verification.enabled' => true, 'email-verification.connection' => 'deferred',
            'app.frontend_url' => 'https://frontend.example.test',
            'services.brevo.key' => 'PRIVATE_API_KEY', 'mail.default' => 'log',
            'logging.default' => 'captured',
            'logging.channels.captured' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug'],
            'logging.channels.password_recovery' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'info'],
        ]);
        Http::preventStrayRequests();
    }

    private function enrolled(): User
    {
        $user = User::factory()->create(['email' => 'private-recipient@example.test']);
        $user->forceFill(['email_verified_at' => null, 'email_verification_required_at' => now(), 'email_verification_version' => 1])->save();

        return $user->refresh();
    }

    private function assertSafeLogs(): void
    {
        foreach (['captured', 'password_recovery'] as $channel) {
            $records = Log::channel($channel)->getLogger()->getHandlers()[0]->getRecords();
            $serialized = json_encode($records);
            foreach (['PRIVATE_', 'private-recipient', 'signature=', 'proof=', 'htmlContent', 'api-key', 'verify-email#'] as $secret) {
                $this->assertStringNotContainsString($secret, $serialized);
            }
            foreach ($records as $record) {
                $this->assertSame([], $record->extra);
                $this->assertSame([], array_diff(array_keys($record->context), ['stage', 'failure_category', 'http_status']));
            }
        }
    }

    public function test_brevo_delivery_uses_only_configured_origin_with_an_expiring_fragment_proof(): void
    {
        $user = $this->enrolled();
        $this->freezeTime();
        $sent = null;
        $sentOptions = null;
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function ($request, $options) use (&$sent, &$sentOptions) {
            $sent = $request;
            $sentOptions = $options;

            return Http::response(['messageId' => 'PRIVATE_PROVIDER_ID'], 201);
        }]);
        // Host/forwarded headers cannot select a verification destination.
        $this->withHeaders(['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example']);
        (new SendEmailVerification($user->id, 1))->handle(app(EmailVerification::class));
        Http::assertSentCount(1);
        $request = $sent;
        $options = $sentOptions;
        $this->assertTrue($request->hasHeader('api-key', 'PRIVATE_API_KEY'));
        $this->assertSame($user->email, $request['to'][0]['email']);
        $this->assertSame('Verify your PWDWork email address', $request['subject']);
        preg_match('/href="([^"]+)"/', $request['htmlContent'], $match);
        $link = html_entity_decode($match[1], ENT_QUOTES);
        $this->assertStringStartsWith('https://frontend.example.test/verify-email#proof=', $link);
        $this->assertNull(parse_url($link, PHP_URL_QUERY));
        parse_str(parse_url($link, PHP_URL_FRAGMENT), $fragment);
        $proof = $fragment['proof'];
        parse_str(parse_url($proof, PHP_URL_QUERY), $fields);
        $this->assertSame(now()->addMinutes(60)->timestamp, (int) $fields['expires']);
        $this->assertStringNotContainsString($user->email, $proof);
        $this->assertFalse($options['allow_redirects']);
        $this->assertTrue($options['verify']);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->actingAs($user, 'web')->withHeader('Origin', 'http://localhost:5173')
            ->postJson('/api/email/verification/confirm', ['proof' => $proof])->assertOk();
        $this->assertSafeLogs();
        $this->assertSame('log', config('mail.default'));
    }

    public function test_deferred_send_runs_only_after_response_and_needs_no_database_worker(): void
    {
        $user = $this->enrolled();
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'PRIVATE_PROVIDER_ID'], 201)]);
        $this->actingAs($user, 'web');
        $request = Request::create('/api/email/verification/resend', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173',
        ], content: '{}');
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $this->assertSame(202, $response->getStatusCode());
        Http::assertNothingSent();
        $this->assertCount(1, app(DeferredCallbackCollection::class));
        $kernel->terminate($request, $response);
        Http::assertSentCount(1);
        $kernel->terminate($request, $response);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSafeLogs();
    }

    public function test_database_job_is_encrypted_and_contains_no_rendered_link_or_email(): void
    {
        $user = $this->enrolled();
        $id = app('queue')->connection('database')->push(new SendEmailVerification($user->id, 1), '', 'email-verifications');
        $payload = DB::table('jobs')->where('id', $id)->value('payload');
        foreach ([$user->email, 'signature', 'proof=', 'Verify your PWDWork'] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
        $command = app('encrypter')->decrypt(json_decode($payload, true)['data']['command']);
        $this->assertStringContainsString(SendEmailVerification::class, $command);
        $this->assertStringNotContainsString($user->email, $command);
        Http::assertNothingSent();
    }

    public function test_initial_registration_sends_once_at_termination_after_profile_creation(): void
    {
        $accountAndProfilePresent = false;
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function () use (&$accountAndProfilePresent) {
            $user = User::where('email', 'initial@example.test')->first();
            $accountAndProfilePresent = $user && $user->applicantProfile()->exists() && $user->requiresEmailVerification();

            return Http::response(['messageId' => 'PRIVATE_PROVIDER_ID'], 201);
        }]);
        $request = Request::create('/api/register', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:5173',
        ], content: json_encode(['name' => 'Example', 'email' => 'initial@example.test', 'password' => 'password', 'role' => 'applicant']));
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $this->assertSame(201, $response->getStatusCode());
        Http::assertNothingSent();
        $this->assertFalse(User::firstOrFail()->hasVerifiedEmail());
        $kernel->terminate($request, $response);
        Http::assertSentCount(1);
        $this->assertTrue($accountAndProfilePresent);
        $this->assertNull(User::firstOrFail()->email_ownership_verified_at);
        $this->assertSafeLogs();
    }

    public static function rejectedResponses(): array
    {
        return [[401, ['message' => 'PRIVATE_PROVIDER_BODY']], [403, ['message' => 'PRIVATE_PROVIDER_BODY']],
            [429, ['message' => 'PRIVATE_PROVIDER_BODY']], [500, ['message' => 'PRIVATE_PROVIDER_BODY']],
            [201, []], [302, ['messageId' => 'PRIVATE_PROVIDER_ID']]];
    }

    #[DataProvider('rejectedResponses')]
    public function test_provider_failures_are_safe_and_never_verify_the_account(int $status, array $body): void
    {
        $user = $this->enrolled();
        Http::fake(['*' => Http::response($body, $status)]);
        try {
            (new SendEmailVerification($user->id, 1))->handle(app(EmailVerification::class));
            $this->fail('Provider rejection must fail delivery.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Email verification delivery failed. Check protected delivery monitoring.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertSafeLogs();
    }

    public function test_connection_failure_is_sanitized_and_stale_or_verified_jobs_do_not_send(): void
    {
        $user = $this->enrolled();
        Http::fake(['*' => fn () => throw new ConnectionException('PRIVATE_API_KEY PRIVATE_BODY')]);
        try {
            (new SendEmailVerification($user->id, 1))->handle(app(EmailVerification::class));
            $this->fail('Connection failure must fail delivery.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('PRIVATE_', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $count = count(Http::recorded());
        (new SendEmailVerification($user->id, 0))->handle(app(EmailVerification::class));
        $user->markEmailAsVerified();
        (new SendEmailVerification($user->id, 1))->handle(app(EmailVerification::class));
        $this->assertCount($count, Http::recorded());
        $this->assertSafeLogs();
    }

    public function test_log_mailer_missing_credentials_and_unsafe_frontend_are_rejected(): void
    {
        $user = $this->enrolled();
        config(['mail.mailers.brevo.transport' => 'log']);
        $this->assertDeliveryRefused($user);
        config(['mail.mailers.brevo.transport' => 'brevo', 'services.brevo.key' => '']);
        $this->assertDeliveryRefused($user);
        config(['services.brevo.key' => 'PRIVATE_API_KEY', 'app.frontend_url' => 'https://user:PRIVATE_PASSWORD@evil.example/?token=PRIVATE_TOKEN']);
        $this->assertDeliveryRefused($user);
        Http::assertNothingSent();
        $this->assertSafeLogs();
    }

    private function assertDeliveryRefused(User $user): void
    {
        try {
            (new SendEmailVerification($user->id, 1))->handle(app(EmailVerification::class));
            $this->fail('Unsafe delivery configuration must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('PRIVATE_', $exception->getMessage());
        }
    }
}
