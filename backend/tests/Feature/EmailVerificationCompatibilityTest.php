<?php

namespace Tests\Feature;

use App\Jobs\SendEmailVerification;
use App\Models\User;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationCompatibilityTest extends TestCase
{
    public static function accountRoles(): array
    {
        return [['applicant'], ['employer'], ['admin']];
    }

    public static function registrationRoles(): array
    {
        return [['applicant'], ['employer']];
    }

    #[DataProvider('accountRoles')]
    public function test_disabled_release_supports_existing_roles_sessions_serialization_and_writes_on_old_schema(string $role): void
    {
        $this->legacySchema(function () use ($role) {
            config(['email-verification.enabled' => false, 'session.driver' => 'database']);
            Queue::fake();
            $user = User::factory()->create(['role' => $role]);
            if ($role === 'employer') {
                $user->employer()->create(['company_name' => 'Existing company', 'verification_status' => 'verified']);
            }
            $before = $user->fresh()->getAttributes();
            DB::connection()->enableQueryLog();
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk()
                ->assertJsonPath('user.role', $role)->assertJsonPath('user.email_verification_required', false);
            $sessionId = session()->getId();
            $this->assertTrue(DB::table('sessions')->where('id', $sessionId)->exists());
            $this->withCredentials()->withCookie(config('session.cookie'), $sessionId);
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/user')->assertOk()->assertJsonPath('user.email_verification_available', false);
            $this->getJson('/api/email/verification')->assertOk();
            $this->postJson('/api/email/verification/resend')->assertNotFound();
            $this->postJson('/api/email/verification/confirm', ['proof' => 'invalid'])->assertNotFound();
            $this->getJson('/api/notifications')->assertOk();
            $this->getJson('/api/jobs')->assertOk();
            $this->assertSame($sessionId, session()->getId());
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertArrayNotHasKey('email_ownership_verified_at', $user->fresh()->toArray());
            if ($role === 'admin') {
                foreach (['overview', 'users', 'employers', 'jobs', 'reports', 'audit-logs'] as $endpoint) {
                    $this->getJson('/api/admin/'.$endpoint)->assertOk();
                }
            } else {
                $this->getJson('/api/profile')->assertOk();
                $this->putJson('/api/profile', $role === 'applicant' ? ['name' => 'Updated jobseeker'] : ['companyName' => 'Existing company'])->assertOk();
                foreach ($role === 'applicant' ? ['saved-jobs', 'applications', 'recommendations', 'resumes'] : ['jobs', 'applications', 'verification-history'] as $endpoint) {
                    $this->getJson('/api/'.$role.'/'.$endpoint)->assertOk();
                }
            }
            $token = $user->createToken('old-schema-test')->plainTextToken;
            $this->postJson('/api/logout')->assertOk();
            $this->app['auth']->forgetGuards();
            $this->withToken($token)->getJson('/api/notifications')->assertOk();
            $this->withoutToken();
            $reset = Password::createToken($user);
            $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $reset, 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'new-password'])->assertOk();
            $queries = DB::connection()->getQueryLog();
            $this->assertNotEmpty($queries);
            foreach (['email_ownership_verified_at', 'email_verification_required_at', 'email_verification_version'] as $column) {
                $this->assertStringNotContainsString($column, implode("\n", array_column($queries, 'query')));
            }
            DB::connection()->disableQueryLog();
            Queue::assertNotPushed(SendEmailVerification::class);
            Http::assertNothingSent();
        });
    }

    #[DataProvider('registrationRoles')]
    public function test_disabled_registration_and_profile_writes_need_no_new_columns(string $role): void
    {
        $this->legacySchema(function () use ($role) {
            config(['email-verification.enabled' => false]);
            $this->postJson('/api/register', [
                'name' => 'Old schema signup', 'email' => 'signup@example.test', 'password' => 'password',
                'role' => $role, 'company_name' => 'New company',
            ])->assertCreated()->assertJsonPath('user.email_verification_required', false);
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/user')->assertOk();
            $this->putJson('/api/profile', $role === 'applicant' ? ['name' => 'Updated name'] : ['companyName' => 'Updated company'])->assertOk();
            $this->getJson('/api/profile')->assertOk();
            $this->assertSame(1, User::count());
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        });
    }

    private function legacySchema(callable $check): void
    {
        // An additional entirely in-memory SQLite connection; never an existing database.
        config(['database.connections.verification_compat' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('verification_compat');
        try {
            foreach (glob(database_path('migrations/*.php')) as $path) {
                if (! str_ends_with($path, '2026_10_26_000001_add_email_ownership_verification_to_users.php')) {
                    (require $path)->up();
                }
            }
            Queue::fake([SendEmailVerification::class]);
            Http::preventStrayRequests();
            $this->withHeader('Origin', 'http://localhost:5173');
            $check();
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('verification_compat');
        }
    }

    public function test_disabled_compatibility_release_works_before_migration_without_changing_existing_sessions(): void
    {
        $this->legacySchema(function () {
            config(['email-verification.enabled' => false]);
            $this->assertFalse(Schema::hasColumn('users', 'email_ownership_verified_at'));
            $user = User::factory()->admin()->create()->refresh();
            $before = $user->getAttributes();
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk()
                ->assertJsonPath('user.email_verification_required', false)->assertJsonPath('user.email_verified', false);
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/admin/overview')->assertOk();
            $this->getJson('/api/user')->assertOk();
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->postJson('/api/register', ['name' => 'Old schema', 'email' => 'old-schema@example.test', 'password' => 'password', 'role' => 'applicant'])
                ->assertCreated()->assertJsonPath('user.email_verification_required', false)->assertJsonPath('user.email_verified', false);
            Queue::assertNothingPushed();
            Http::assertNothingSent();
        });
    }

    public function test_premature_activation_refuses_new_accounts_but_preserves_existing_access(): void
    {
        $this->legacySchema(function () {
            config(['email-verification.enabled' => true, 'email-verification.connection' => 'deferred']);
            $user = User::factory()->admin()->create()->refresh();
            $this->postJson('/api/register', ['name' => 'New', 'email' => 'new@example.test', 'password' => 'password', 'role' => 'applicant'])
                ->assertStatus(503)->assertDontSee('SQL')->assertDontSee('email_ownership_verified_at');
            $this->assertSame(1, User::count());
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
            $this->getJson('/api/admin/overview')->assertOk();
            Queue::assertNothingPushed();
        });
    }

    public function test_additive_migration_preserves_data_and_accepts_older_writer_column_sets(): void
    {
        $this->legacySchema(function () {
            $user = User::factory()->create()->refresh();
            $before = $user->getAttributes();
            DB::table('sessions')->insert(['id' => 'existing-session', 'user_id' => $user->id, 'payload' => 'existing-payload', 'last_activity' => 100]);
            $migration = require database_path('migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php');
            $migration->up();
            $after = $user->fresh()->getAttributes();
            $this->assertSame($before, array_intersect_key($after, $before));
            $this->assertNull($after['email_ownership_verified_at']);
            $this->assertNull($after['email_verification_required_at']);
            $this->assertSame(0, $after['email_verification_version']);
            $this->assertSame('existing-payload', DB::table('sessions')->value('payload'));
            DB::table('users')->insert([
                'name' => 'Older writer', 'email' => 'older-writer@example.test', 'password' => $user->password,
                'role' => 'employer', 'status' => 'active', 'email_verified_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $older = User::where('email', 'older-writer@example.test')->firstOrFail();
            $this->assertFalse($older->hasVerifiedEmail());
            $this->assertFalse($older->requiresEmailVerification());
            $this->assertSame(2, User::count());
            try {
                $migration->down();
                $this->fail('Ownership evidence must not be dropped by rollback.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('compatible application release', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasColumn('users', 'email_ownership_verified_at'));
            $this->assertSame(2, User::count());
        });
    }

    public function test_enrollment_defaults_to_off_and_inherits_deferred_without_changing_password_recovery(): void
    {
        $settings = require config_path('email-verification.php');
        $this->assertFalse($settings['enabled']);
        $this->assertSame('deferred', $settings['connection']);
        $this->assertSame('email-verifications', $settings['queue']);
        $this->assertFalse(config('email-verification.enabled'));
    }

    public function test_mysql_84_grammar_compiles_only_the_three_additive_columns_without_connecting(): void
    {
        $connection = new class(fn () => throw new \RuntimeException('No MySQL connection is permitted.'), 'synthetic') extends MySqlConnection
        {
            public function getServerVersion(): string
            {
                return '8.4.0';
            }
        };
        $original = Schema::getFacadeRoot();
        try {
            Schema::swap($connection->getSchemaBuilder());
            $queries = $connection->pretend(function () {
                (require database_path('migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php'))->up();
            });
            $this->assertSame([
                'alter table `users` add `email_ownership_verified_at` timestamp null',
                'alter table `users` add `email_verification_required_at` timestamp null',
                "alter table `users` add `email_verification_version` bigint unsigned not null default '0'",
            ], array_column($queries, 'query'));
            $this->assertSame([[], [], []], array_column($queries, 'bindings'));
        } finally {
            Schema::swap($original);
        }
    }
}
