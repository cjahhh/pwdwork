<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Support\PasswordRecoveryDiagnostics;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordRecoveryDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $diagnostics;

    private TestHandler $generalLogs;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.frontend_url' => 'https://frontend.example.test',
            'queue.password_reset_connection' => 'deferred',
            'mail.default' => 'log', 'mail.password_reset_mailer' => 'brevo',
            'mail.from.address' => 'sender@example.test', 'mail.from.name' => 'PWDWork',
            'services.brevo.key' => 'PRIVATE_API_KEY',
            'logging.default' => 'captured',
            'logging.channels.captured' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'warning'],
            'logging.channels.password_recovery' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'info'],
        ]);
        $this->diagnostics = Log::channel('password_recovery')->getLogger()->getHandlers()[0];
        $this->generalLogs = Log::channel('captured')->getLogger()->getHandlers()[0];
        Http::preventStrayRequests();
    }

    private function stages(): array
    {
        return array_map(fn ($record) => $record->context['stage'], $this->diagnostics->getRecords());
    }

    private function assertSafeLogs(): void
    {
        foreach ($this->diagnostics->getRecords() as $record) {
            $this->assertSame('Password recovery processing.', $record->message);
            $this->assertSame([], $record->extra);
            $this->assertSame([], array_diff(array_keys($record->context), ['stage', 'failure_category', 'http_status']));
        }
        $serialized = json_encode([$this->diagnostics->getRecords(), $this->generalLogs->getRecords()]);
        foreach (['PRIVATE_', '@example.test', 'reset-password#', 'api-key', 'htmlContent', 'messageId'] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
    }

    public function test_brevo_send_occurs_only_at_kernel_termination_and_overrides_the_log_mailer(): void
    {
        Http::fake(['https://api.brevo.com/v3/smtp/email' => function ($request, $options) {
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->hasHeader('api-key', 'PRIVATE_API_KEY'));
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertTrue($request->hasHeader('Content-Type', 'application/json'));
            $this->assertSame('sender@example.test', $request['sender']['email']);
            $this->assertSame('PWDWork', $request['sender']['name']);
            $this->assertSame('private-recipient@example.test', $request['to'][0]['email']);
            $this->assertStringContainsString('/reset-password#token=', $request['htmlContent']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);

            return Http::response(['messageId' => 'PRIVATE_PROVIDER_ID'], 201);
        }]);
        $user = User::factory()->create(['email' => 'private-recipient@example.test']);
        $request = Request::create('/api/forgot-password', 'POST', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], content: json_encode(['email' => $user->email]));
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);

        $this->assertSame(202, $response->getStatusCode());
        $body = $response->getContent();
        Http::assertNothingSent();
        $this->assertSame(['dispatch_accepted'], $this->stages());
        $this->assertCount(1, app(DeferredCallbackCollection::class));
        $this->assertDatabaseCount('password_reset_tokens', 0);

        $kernel->terminate($request, $response);

        $this->assertSame($body, $response->getContent());
        $this->assertSame([
            'dispatch_accepted', 'job_started', 'mailer_validation', 'broker_started',
            'notification_started', 'notification_ready', 'provider_started', 'provider_accepted', 'broker_completed',
        ], $this->stages());
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertSentCount(1);
        // Repeated termination must not resend the callback.
        $kernel->terminate($request, $response);
        Http::assertSentCount(1);
        $this->assertSame('log', config('mail.default'));
        $this->assertSafeLogs();
    }

    public static function rejectedResponses(): array
    {
        return [
            'invalid request' => [400, ['message' => 'PRIVATE_PROVIDER_BODY'], 'provider_rejected'],
            'authentication' => [401, ['message' => 'PRIVATE_PROVIDER_BODY'], 'provider_authentication'],
            'permission' => [403, ['message' => 'PRIVATE_PROVIDER_BODY'], 'provider_permission'],
            'rate limit' => [429, ['message' => 'PRIVATE_PROVIDER_BODY'], 'provider_rate_limit'],
            'server' => [503, ['message' => 'PRIVATE_PROVIDER_BODY'], 'provider_server'],
            'redirect' => [302, ['message' => 'PRIVATE_PROVIDER_BODY'], 'provider_rejected'],
            'missing identifier' => [201, ['message' => 'PRIVATE_PROVIDER_BODY'], 'provider_response'],
            'empty identifier' => [201, ['messageId' => ''], 'provider_response'],
            'whitespace identifier' => [201, ['messageId' => '  '], 'provider_response'],
            'invalid json' => [201, 'PRIVATE_INVALID_JSON', 'provider_response'],
        ];
    }

    #[DataProvider('rejectedResponses')]
    public function test_deferred_rejections_are_diagnosed_and_leave_the_same_generic_response(int $status, array|string $body, string $category): void
    {
        $this->app->instance('env', 'production');
        Http::fake(['*' => Http::response($body, $status)]);
        $user = User::factory()->create();
        $response = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(202);
        $this->assertContains('job_started', $this->stages());
        $this->assertContains('job_failed', $this->stages());
        $this->assertNotContains('broker_completed', $this->stages());
        $failure = array_values(array_filter($this->diagnostics->getRecords(), fn ($record) => $record->context['stage'] === 'provider_failed'));
        $this->assertCount(1, $failure);
        $this->assertSame(['stage' => 'provider_failed', 'failure_category' => $category, 'http_status' => $status], $failure[0]->context);
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(202);
        $this->assertSame($response->json(), $unknown->json());
        Http::assertSentCount(1);
        // Deferred jobs have no worker retries or durable failed-job entry.
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSafeLogs();
    }

    public function test_connection_failures_have_no_http_status_or_sensitive_exception_context(): void
    {
        $this->app->instance('env', 'production');
        Http::fake(['*' => fn () => throw new ConnectionException('PRIVATE_REQUEST_BODY_AND_KEY')]);
        $this->postJson('/api/forgot-password', ['email' => User::factory()->create()->email])->assertStatus(202);
        $failure = array_values(array_filter($this->diagnostics->getRecords(), fn ($record) => $record->context['stage'] === 'provider_failed'));
        $this->assertSame(['stage' => 'provider_failed', 'failure_category' => 'connection'], $failure[0]->context);
        $this->assertSafeLogs();
    }

    public function test_unknown_and_broker_throttled_requests_complete_without_provider_calls_or_account_outcome_logs(): void
    {
        Http::fake(['*' => Http::response(['messageId' => 'PRIVATE_PROVIDER_ID'], 201)]);
        $user = User::factory()->create();
        $known = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(202);
        $throttled = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(202);
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(202);
        $this->assertSame($known->json(), $throttled->json());
        $this->assertSame($known->json(), $unknown->json());
        Http::assertSentCount(1);
        $this->assertSame(3, count(array_filter($this->stages(), fn ($stage) => $stage === 'broker_completed')));
        $this->assertSame(1, count(array_filter($this->stages(), fn ($stage) => $stage === 'provider_started')));
        $this->assertSafeLogs();
    }

    public function test_dispatch_errors_have_only_fixed_diagnostics_and_a_generic_503(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('PRIVATE_QUEUE_CREDENTIALS'));
        $this->postJson('/api/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(503)
            ->assertJsonPath('message', 'Password recovery is temporarily unavailable. Please try again later.');
        $this->assertSame(['dispatch_failed'], $this->stages());
        $this->assertSame('dispatch', $this->diagnostics->getRecords()[0]->context['failure_category']);
        $this->assertSafeLogs();
    }

    public function test_unsafe_mailer_is_diagnosed_before_account_lookup_and_no_mail_is_sent(): void
    {
        config(['mail.password_reset_mailer' => 'log']);
        $this->app->instance('env', 'production');
        $this->postJson('/api/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(202);
        Http::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame('configuration', $this->diagnostics->getRecords()[3]->context['failure_category']);
        $this->assertSafeLogs();
    }

    public function test_diagnostics_fail_closed_for_unknown_values_and_do_not_block_delivery_when_logging_fails(): void
    {
        PasswordRecoveryDiagnostics::record('PRIVATE_EMAIL_OR_TOKEN', 'none');
        PasswordRecoveryDiagnostics::record('job_failed', 'PRIVATE_EXCEPTION');
        $this->assertSame([], $this->diagnostics->getRecords());
        PasswordRecoveryDiagnostics::record('provider_failed', 'connection', 999);
        $this->assertSame(['stage' => 'provider_failed', 'failure_category' => 'connection'], $this->diagnostics->getRecords()[0]->context);
        Log::shouldReceive('channel')->with('password_recovery')->andThrow(new \RuntimeException('PRIVATE_LOG_FAILURE'));
        Http::fake(['*' => Http::response(['messageId' => 'PRIVATE_PROVIDER_ID'], 201)]);
        (new SendPasswordResetLink(User::factory()->create()->email))->handle();
        Http::assertSentCount(1);
    }

    public function test_mail_and_queue_configuration_read_the_documented_environment_variables(): void
    {
        // Override only this process's synthetic test values, then restore them.
        $repository = RepositoryBuilder::createWithDefaultAdapters()->addAdapter(PutenvAdapter::class)->make();
        $values = [
            'PASSWORD_RESET_MAILER' => 'brevo', 'MAIL_MAILER' => 'log',
            'PASSWORD_RESET_QUEUE_CONNECTION' => 'deferred', 'QUEUE_CONNECTION' => 'database',
            'BREVO_API_KEY' => 'PRIVATE_CONFIG_KEY', 'MAIL_FROM_ADDRESS' => 'sender@example.test',
        ];
        $original = [];
        try {
            foreach ($values as $name => $value) {
                $original[$name] = $repository->get($name);
                $repository->set($name, $value);
            }
            $mail = require config_path('mail.php');
            $queue = require config_path('queue.php');
            $services = require config_path('services.php');
            $logging = require config_path('logging.php');
            $this->assertSame('brevo', $mail['password_reset_mailer']);
            $this->assertSame('log', $mail['default']);
            $this->assertSame('sender@example.test', $mail['from']['address']);
            $this->assertSame('deferred', $queue['password_reset_connection']);
            $this->assertSame('database', $queue['default']);
            $this->assertSame('PRIVATE_CONFIG_KEY', $services['brevo']['key']);
            $this->assertSame('php://stderr', $logging['channels']['password_recovery']['handler_with']['stream']);
            $this->assertSame('info', $logging['channels']['password_recovery']['level']);
            $this->assertSame([], $logging['channels']['password_recovery']['processors']);
        } finally {
            foreach ($original as $name => $value) {
                $value === null ? $repository->clear($name) : $repository->set($name, $value);
            }
        }
    }
}
