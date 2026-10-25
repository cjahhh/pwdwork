<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordResetLink;
use App\Mail\PasswordResetEmail;
use App\Models\User;
use App\Services\PasswordRecoveryMail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class PasswordRecoveryMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://frontend.example.test', 'mail.password_reset_mailer' => 'array']);
        Http::preventStrayRequests();
    }

    private function account(string $role = 'applicant', string $status = 'active'): User
    {
        return User::factory()->create(['role' => $role, 'status' => $status]);
    }

    private function payload(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'new-password'];
    }

    public function test_broker_notification_has_a_trusted_fragment_link_and_a_hashed_expiring_token(): void
    {
        Notification::fake();
        $user = $this->account();
        (new SendPasswordResetLink($user->email))->handle();
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            $parts = parse_url($mail->actionUrl);
            parse_str($parts['fragment'], $values);
            $this->assertSame('https', $parts['scheme']);
            $this->assertSame('frontend.example.test', $parts['host']);
            $this->assertSame('/reset-password', $parts['path']);
            $this->assertArrayNotHasKey('query', $parts);
            $this->assertTrue($values['email'] === $user->email && $values['token'] === $notification->token);
            $this->assertTrue(Hash::check($notification->token, DB::table('password_reset_tokens')->where('email', $user->email)->value('token')));
            $this->assertSame(60, $mail->expiresMinutes);

            return true;
        });
    }

    public function test_deferred_recovery_sends_branded_mail_without_a_database_worker_and_keeps_unknown_email_private(): void
    {
        config(['queue.password_reset_connection' => 'deferred']);
        $user = $this->account();
        $known = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(202);
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])->assertStatus(202);
        $this->assertSame($known->json(), $unknown->json());
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertTrue($messages->first()->getOriginalMessage()->getTo()[0]->getAddress() === $user->email);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $message = app(PasswordRecoveryMail::class)->message($user, 'synthetic-token');
        Mail::fake();
        Mail::send($message);
        Mail::assertSent(PasswordResetEmail::class, fn ($mail) => $mail->hasTo($user->email));
        Mail::assertNothingQueued();
        $html = $message->render();
        $text = view('emails.password-reset-text', ['resetUrl' => $message->actionUrl, 'expiresMinutes' => 60])->render();
        foreach ([$html, $text] as $body) {
            $this->assertTrue(str_contains($body, 'PWDWork') && str_contains($body, '60 minutes') && str_contains($body, 'once'));
            $this->assertTrue(str_contains($body, 'https://frontend.example.test/reset-password#'));
        }
        $this->assertSame('Reset your PWDWork password', $message->subject);
    }

    public function test_reset_validation_invalid_tokens_expiration_and_replay_preserve_security(): void
    {
        $user = $this->account();
        $token = Password::createToken($user);
        $valid = $this->payload($user, $token);
        $this->postJson('/api/reset-password', [...$valid, 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/reset-password', [...$valid, 'password_confirmation' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/reset-password', [...$valid, 'token' => 'incorrect'])->assertUnprocessable()->assertJsonPath('message', 'This reset link is invalid or expired. Request a new link.');
        $this->postJson('/api/reset-password', [...$valid, 'password' => str_repeat('é', 37), 'password_confirmation' => str_repeat('é', 37)])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->travel(61)->minutes();
        $this->postJson('/api/reset-password', $valid)->assertUnprocessable();
        $this->travelBack();
        $this->postJson('/api/reset-password', $valid)->assertOk();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->postJson('/api/reset-password', $valid)->assertUnprocessable();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'new-password'])->assertOk();
    }

    public function test_another_email_or_a_replaced_token_cannot_reset_the_account(): void
    {
        $user = $this->account();
        $other = $this->account();
        $old = Password::createToken($user);
        $new = Password::createToken($user);
        $this->postJson('/api/reset-password', $this->payload($other, $new))->assertUnprocessable();
        $this->postJson('/api/reset-password', $this->payload($user, $old))->assertUnprocessable();
        $this->postJson('/api/reset-password', $this->payload($user, $new))->assertOk();
        $this->assertTrue(Hash::check('password', $other->fresh()->password));
    }

    public function test_reset_preserves_roles_and_revokes_sessions_without_unlocking_suspended_accounts(): void
    {
        config(['session.driver' => 'database']);
        $this->withHeader('Origin', 'http://localhost:5173');
        foreach (['applicant', 'employer', 'admin'] as $role) {
            $user = $this->account($role, 'suspended');
            $user->createToken('old');
            DB::table('sessions')->insert(['id' => 'session-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
            $this->postJson('/api/reset-password', $this->payload($user, Password::createToken($user)))->assertOk();
            $this->assertSame('suspended', $user->fresh()->status);
            $this->assertSame($role, $user->fresh()->role);
            $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
            $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'new-password'])->assertForbidden()->assertJsonPath('code', 'account_inactive');
        }
    }

    public function test_request_and_broker_throttles_are_retained(): void
    {
        Notification::fake();
        $user = $this->account();
        (new SendPasswordResetLink($user->email))->handle();
        (new SendPasswordResetLink($user->email))->handle();
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->travel(61)->seconds();
        (new SendPasswordResetLink($user->email))->handle();
        Notification::assertSentToTimes($user, ResetPassword::class, 2);
        Queue::fake();
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])->assertStatus(202);
        }
        $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])->assertStatus(429);
        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/reset-password', $this->payload($user, 'invalid'))->assertUnprocessable();
        }
        $this->postJson('/api/reset-password', $this->payload($user, 'invalid'))->assertStatus(429);
    }

    public function test_recovery_refuses_log_fallbacks_instead_of_recording_tokens(): void
    {
        Mail::fake();
        $user = $this->account();
        foreach (['log', 'failover', 'roundrobin', 'missing'] as $mailer) {
            config(['mail.password_reset_mailer' => $mailer]);
            try {
                (new SendPasswordResetLink($user->email))->handle();
                $this->fail('Unsafe mail transport was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Password recovery delivery failed. Check the mail configuration and provider dashboard.', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_frontend_url_validation_rejects_untrusted_formats_and_insecure_production_links(): void
    {
        $user = $this->account();
        foreach (['javascript:alert(1)', 'https://operator:secret@frontend.example.test', 'https://frontend.example.test?redirect=x', 'https://frontend.example.test#x', 'http://remote.example.test'] as $url) {
            config(['app.frontend_url' => $url]);
            try {
                app(PasswordRecoveryMail::class)->url($user, 'synthetic-token');
                $this->fail('Unsafe reset frontend URL was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Password recovery frontend URL is not configured safely.', $exception->getMessage());
            }
        }
        $this->app->instance('env', 'production');
        config(['app.frontend_url' => 'http://localhost:5173']);
        $this->expectException(RuntimeException::class);
        app(PasswordRecoveryMail::class)->url($user, 'synthetic-token');
    }

    public function test_brevo_uses_a_fixed_https_endpoint_with_the_same_branded_broker_email(): void
    {
        config(['mail.password_reset_mailer' => 'brevo', 'services.brevo.key' => 'synthetic-key']);
        Http::fake(['https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'accepted-test-message'], 201)]);
        $user = $this->account();
        (new SendPasswordResetLink($user->email))->handle();
        Http::assertSent(function ($request) use ($user) {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'synthetic-key')
                && $request['to'][0]['email'] === $user->email
                && $request['subject'] === 'Reset your PWDWork password'
                && str_contains($request['htmlContent'], '/reset-password#token=')
                && ! isset($request['htmlUrl']) && ! isset($request['templateId']);
        });
        Http::assertSentCount(1);
    }

    public function test_https_failures_are_sanitized_and_never_redirect_or_fall_back_to_smtp(): void
    {
        config(['mail.password_reset_mailer' => 'brevo', 'services.brevo.key' => 'synthetic-key']);
        $user = $this->account();
        foreach ([301, 429, 500, 201] as $status) {
            Http::fake(['*' => function ($request, $options) use ($status) {
                $this->assertFalse($options['allow_redirects']);
                $this->assertTrue($options['verify']);

                return Http::response(['message' => 'SYNTHETIC-PRIVATE-PROVIDER-BODY'], $status);
            }]);
            try {
                Mail::mailer('brevo')->send(app(PasswordRecoveryMail::class)->message($user, 'synthetic-token'));
                $this->fail('An unaccepted provider response was treated as successful.');
            } catch (TransportException $exception) {
                $this->assertNull($exception->getPrevious());
                $this->assertFalse(str_contains($exception->getMessage(), 'SYNTHETIC-PRIVATE'));
            }
        }
        Http::fake(['*' => fn () => throw new ConnectionException('SYNTHETIC-PRIVATE-TIMEOUT')]);
        try {
            Mail::mailer('brevo')->send(app(PasswordRecoveryMail::class)->message($user, 'synthetic-token'));
            $this->fail('Connection failure was treated as successful.');
        } catch (TransportException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertFalse(str_contains($exception->getMessage(), 'SYNTHETIC-PRIVATE'));
        }
    }

    public function test_missing_https_credentials_fail_without_making_a_request(): void
    {
        config(['mail.password_reset_mailer' => 'brevo', 'services.brevo.key' => '']);
        try {
            (new SendPasswordResetLink($this->account()->email))->handle();
            $this->fail('Missing provider configuration was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
        }
        Http::assertNothingSent();
    }

    public function test_a_delivery_mailer_must_be_selected_explicitly_when_the_default_is_log(): void
    {
        config(['mail.default' => 'log', 'mail.password_reset_mailer' => null]);
        try {
            app(PasswordRecoveryMail::class)->mailer();
            $this->fail('Recovery silently changed the mail provider.');
        } catch (RuntimeException $exception) {
            $this->assertSame('log', config('mail.default'));
        }
        config(['mail.password_reset_mailer' => 'brevo']);
        $this->assertSame('brevo', app(PasswordRecoveryMail::class)->mailer());
        $this->assertSame('log', config('mail.default'));
    }

    public function test_inline_or_discarding_recovery_connections_fail_equally_before_account_lookup(): void
    {
        Mail::fake();
        $user = $this->account();
        foreach (['sync', 'null'] as $connection) {
            config(['queue.password_reset_connection' => $connection]);
            $known = $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(503);
            $unknown = $this->postJson('/api/forgot-password', ['email' => 'missing@example.test'])->assertStatus(503);
            $this->assertSame($known->json(), $unknown->json());
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_provider_errors_and_local_dispatch_errors_never_log_secrets_or_email_contents(): void
    {
        $log = new TestHandler;
        Log::swap(new Logger('safe-test', [$log]));
        config(['mail.password_reset_mailer' => 'brevo', 'services.brevo.key' => 'SYNTHETIC-SECRET-KEY']);
        Http::fake(['*' => Http::response(['message' => 'SYNTHETIC-PRIVATE-RESET-URL'], 401)]);
        try {
            (new SendPasswordResetLink($this->account()->email))->handle();
            $this->fail('Rejected delivery was treated as successful.');
        } catch (RuntimeException $exception) {
            $this->assertNull($exception->getPrevious());
            report($exception);
        }
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('SYNTHETIC-SECRET-QUEUE-URL'));
        $this->postJson('/api/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(503)->assertJsonPath('message', 'Password recovery is temporarily unavailable. Please try again later.');
        $records = json_encode($log->getRecords());
        $this->assertFalse(str_contains($records, 'SYNTHETIC-SECRET') || str_contains($records, 'SYNTHETIC-PRIVATE-RESET'));
    }

    public function test_smtp_factory_uses_laravel_twelve_scheme_and_required_starttls_options(): void
    {
        config(['mail.mailers.smtp.scheme' => 'smtp', 'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => 'smtp.gmail.com', 'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.require_tls' => true, 'mail.mailers.smtp.username' => 'synthetic@example.test', 'mail.mailers.smtp.password' => 'synthetic-only']);
        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertTrue($transport->isTlsRequired());
        $this->assertTrue($transport->isAutoTls());
        // Constructing the transport never opens a connection or sends an email.
        Http::assertNothingSent();
    }
}
