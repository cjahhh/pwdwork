<?php

namespace Tests\Unit;

use const Pwdwork\EmailIncremental\DDL;
use const Pwdwork\EmailIncremental\MIGRATION_PATH;
use const Pwdwork\EmailIncremental\MIGRATION_SHA;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Schema;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Pwdwork\EmailIncremental\authorize;
use function Pwdwork\EmailIncremental\backup;
use function Pwdwork\EmailIncremental\evidence;
use function Pwdwork\EmailIncremental\hashStream;
use function Pwdwork\EmailIncremental\history;
use function Pwdwork\EmailIncremental\migrationArguments;
use function Pwdwork\EmailIncremental\phase;
use function Pwdwork\EmailIncremental\preserved;
use function Pwdwork\EmailIncremental\preview;
use function Pwdwork\EmailIncremental\safeFailure;
use function Pwdwork\EmailIncremental\target;
use function Pwdwork\EmailIncremental\verifyServer;

class EmailVerificationIncrementalSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/.github/scripts/email-verification-incremental.php';
    }

    private function environment(): array
    {
        return ['GITHUB_ACTIONS' => 'true', 'RUNNER_OS' => 'Linux', 'GITHUB_REPOSITORY' => 'cjahhh/pwdwork',
            'GITHUB_REF' => 'refs/heads/main', 'GITHUB_EVENT_NAME' => 'workflow_dispatch', 'GITHUB_RUN_ATTEMPT' => '1',
            'GITHUB_SHA' => str_repeat('a', 40), 'REVIEWED_SHA' => str_repeat('a', 40), 'OPERATION' => 'APPLY',
            'CONFIRMATION' => 'APPLY email ownership pwdwork_prod', 'EV_APPROVAL_VERIFIED' => 'true',
            'EMAIL_VERIFICATION_ENABLED' => 'false', 'DB_HOST' => 'example.aivencloud.com',
            'EXPECTED_DB_HOST' => 'example.aivencloud.com', 'DB_PORT' => '12345', 'EXPECTED_DB_PORT' => '12345',
            'DB_DATABASE' => 'pwdwork_prod', 'DB_USERNAME' => 'migration_operator',
            'EXPECTED_SERVER_UUID' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'];
    }

    public function test_only_reviewed_manual_operation_with_approval_and_disabled_feature_is_allowed(): void
    {
        authorize($this->environment());
        authorize(array_replace($this->environment(), ['OPERATION' => 'PREVIEW', 'CONFIRMATION' => '']));
        target($this->environment());
        $arguments = migrationArguments();
        $this->assertSame([MIGRATION_PATH], $arguments['--path']);
        $this->assertSame('mysql', $arguments['--database']);
        $this->assertTrue($arguments['--force']);
        $this->assertCount(4, $arguments);
    }

    public static function rejectedAuthorization(): array
    {
        return [['GITHUB_ACTIONS', 'false'], ['RUNNER_OS', 'Windows'], ['GITHUB_REPOSITORY', 'attacker/fork'],
            ['GITHUB_REF', 'refs/heads/testing'], ['GITHUB_EVENT_NAME', 'push'], ['GITHUB_RUN_ATTEMPT', '2'],
            ['GITHUB_SHA', 'main'], ['REVIEWED_SHA', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'],
            ['OPERATION', 'migrate:fresh'], ['CONFIRMATION', 'APPLY pwdwork_prod'], ['EV_APPROVAL_VERIFIED', ''],
            ['EMAIL_VERIFICATION_ENABLED', 'true'], ['EMAIL_VERIFICATION_ENABLED', '0']];
    }

    #[DataProvider('rejectedAuthorization')]
    public function test_rejects_unsafe_authorization(string $key, string $value): void
    {
        $this->expectException(RuntimeException::class);
        authorize(array_replace($this->environment(), [$key => $value]));
    }

    public static function rejectedTarget(): array
    {
        return [['DB_HOST', 'other.aivencloud.com'], ['DB_HOST', 'example.aivencloud.com;port=3306'],
            ['EXPECTED_DB_HOST', ''], ['DB_PORT', '3306'], ['EXPECTED_DB_PORT', ''], ['DB_DATABASE', 'mysql'],
            ['EXPECTED_SERVER_UUID', ''], ['DB_USERNAME', 'root']];
    }

    #[DataProvider('rejectedTarget')]
    public function test_rejects_wrong_or_unbound_identity(string $key, string $value): void
    {
        $this->expectException(RuntimeException::class);
        target(array_replace($this->environment(), [$key => $value]));
    }

    public function test_complete_baseline_history_is_required(): void
    {
        history([['migration' => 'old', 'batch' => '1']], ['old.php'], 0);
        $this->addToAssertionCount(1);
    }

    public static function unsafeHistory(): array
    {
        return [[[], 0], [[['migration' => 'old', 'batch' => '1']], 1], [[['migration' => 'old', 'batch' => '1']], 2],
            [[['migration' => 'old', 'batch' => '1']], 3], [[['migration' => 'old', 'batch' => '0']], 0],
            [[['migration' => 'old', 'batch' => '1'], ['migration' => 'old', 'batch' => '2']], 0],
            [[['migration' => 'unknown', 'batch' => '1']], 0],
            [[['migration' => 'old', 'batch' => '1'], ['migration' => '2026_10_26_000001_add_email_ownership_verification_to_users', 'batch' => '2']], 0]];
    }

    #[DataProvider('unsafeHistory')]
    public function test_partial_duplicate_missing_unknown_or_recorded_state_is_refused(array $records, int $columns): void
    {
        $this->expectException(RuntimeException::class);
        history($records, ['old.php'], $columns);
    }

    private function proof(): array
    {
        return ['format' => 1, 'service' => 'mysql-380cabbd', 'database' => 'pwdwork_prod',
            'server_uuid' => $this->environment()['EXPECTED_SERVER_UUID'], 'compatible_release_sha' => str_repeat('a', 40),
            'backup_sha256' => str_repeat('b', 64), 'restored_manifest_sha256' => str_repeat('c', 64),
            'recovery_runbook_sha256' => str_repeat('d', 64), 'private_storage_reference' => 'secure_record_123',
            'recovery_target' => 'isolated-mysql-8.4.11', 'restore_test_passed' => true, 'schema_and_data_match' => true,
            'writers_quiesced' => true, 'live_email_verification_enabled' => false, 'rpo_seconds' => 0,
            'restore_duration_seconds' => 60, 'writers_stopped_utc' => '2026-10-27T00:00:00Z',
            'backup_utc' => '2026-10-27T00:00:30Z', 'restore_verified_utc' => '2026-10-27T00:02:00Z',
            'valid_until_utc' => '2026-10-27T01:00:00Z'];
    }

    private function checkProof(array $proof): void
    {
        backup($proof, $this->environment()['EXPECTED_SERVER_UUID'], str_repeat('a', 40), str_repeat('d', 64), strtotime('2026-10-27T00:05:00Z'));
    }

    public function test_fresh_restore_proof_and_exact_evidence_hash_are_accepted(): void
    {
        $raw = json_encode($this->proof(), JSON_THROW_ON_ERROR);
        $this->checkProof(evidence($raw, hash('sha256', $raw)));
        $this->addToAssertionCount(1);
    }

    public static function unsafeBackup(): array
    {
        return [['service', 'another'], ['database', 'another'], ['server_uuid', 'another'], ['compatible_release_sha', 'main'],
            ['backup_sha256', ''], ['restored_manifest_sha256', ''], ['recovery_runbook_sha256', 'changed'],
            ['private_storage_reference', 'https://signed-private-link?token=secret'], ['recovery_target', 'production'],
            ['restore_test_passed', false], ['schema_and_data_match', false], ['writers_quiesced', false],
            ['live_email_verification_enabled', true], ['rpo_seconds', 60], ['restore_duration_seconds', 0],
            ['backup_utc', '2026-10-26T00:00:00Z'], ['restore_verified_utc', '2026-10-28T00:00:00Z'],
            ['valid_until_utc', '2026-10-27T00:04:59Z'], ['valid_until_utc', '2026-10-28T00:00:00Z'],
            ['writers_stopped_utc', '2026-10-27T00:00:31Z'], ['backup_utc', 'not a date']];
    }

    #[DataProvider('unsafeBackup')]
    public function test_backup_checkboxes_alone_or_stale_inconsistent_proof_cannot_authorize_apply(string $key, mixed $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->checkProof(array_replace($this->proof(), [$key => $value]));
    }

    public function test_changed_evidence_digest_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        evidence('{}', str_repeat('a', 64));
    }

    private function receipt(): array
    {
        return ['format' => 1, 'status' => 'PASS', 'operation' => 'PREVIEW', 'sha' => str_repeat('a', 40),
            'server_uuid' => $this->environment()['EXPECTED_SERVER_UUID'], 'migration_sha256' => MIGRATION_SHA,
            'schema_sha256' => str_repeat('f', 64), 'created_utc' => '2026-10-27T00:00:00Z'];
    }

    public function test_fresh_preview_bound_to_exact_schema_source_and_target(): void
    {
        preview($this->receipt(), $this->environment()['EXPECTED_SERVER_UUID'], str_repeat('a', 40), str_repeat('f', 64), strtotime('2026-10-27T00:05:00Z'));
        $this->addToAssertionCount(1);
    }

    public static function unsafePreview(): array
    {
        return [['status', 'FAIL'], ['operation', 'APPLY'], ['sha', 'main'], ['server_uuid', 'another'],
            ['migration_sha256', 'changed'], ['schema_sha256', 'changed'], ['created_utc', '2026-10-26T00:00:00Z'],
            ['created_utc', '2026-10-28T00:00:00Z']];
    }

    #[DataProvider('unsafePreview')]
    public function test_unreviewed_drifted_or_stale_preview_is_rejected(string $key, string $value): void
    {
        $this->expectException(RuntimeException::class);
        preview(array_replace($this->receipt(), [$key => $value]), $this->environment()['EXPECTED_SERVER_UUID'], str_repeat('a', 40), str_repeat('f', 64), strtotime('2026-10-27T00:05:00Z'));
    }

    public function test_streaming_fingerprint_handles_binary_null_empty_and_every_sensitive_record(): void
    {
        $rows = [['user', 'password-hash', 'admin', null, ''], ['session', "\0\xff", 'payload', 'approved', 'application']];
        $hash = hashStream((function () use ($rows) {
            yield from $rows;
        })());
        $this->assertSame(2, $hash['rows']);
        $this->assertStringNotContainsString('password', json_encode($hash));
        foreach ([[0, 1], [0, 2], [0, 3], [0, 4], [1, 1], [1, 2], [1, 3], [1, 4]] as [$row, $column]) {
            $changed = $rows;
            $changed[$row][$column] = 'modified';
            $this->assertNotSame($hash, hashStream($changed));
        }
        preserved(['users' => $hash], ['users' => $hash]);
    }

    public function test_existing_record_change_stops_success_even_when_row_count_is_unchanged(): void
    {
        $this->expectException(RuntimeException::class);
        preserved(['users' => hashStream([['admin', 'password']])], ['users' => hashStream([['admin', 'changed']])]);
    }

    public function test_cli_fails_before_any_connection_and_suppresses_secret_bearing_settings(): void
    {
        $output = tmpfile();
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/.github/scripts/email-verification-incremental.php'],
            [0 => ['pipe', 'r'], 1 => $output, 2 => $output], $pipes, null,
            ['GITHUB_ACTIONS' => 'false', 'DB_PASSWORD' => 'do-not-log-password', 'APP_KEY' => 'do-not-log-key',
                'OPERATION' => 'https://example.test/verify?signature=do-not-log-proof']);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $this->assertSame(1, proc_close($process));
        rewind($output);
        $result = stream_get_contents($output);
        fclose($output);
        $this->assertSame('FAIL', json_decode($result, true, flags: JSON_THROW_ON_ERROR)['status']);
        $this->assertStringNotContainsString('do-not-log', $result);
        $this->assertStringNotContainsString('signature', $result);
        $this->assertStringNotContainsString('PDO', $result);
    }

    public function test_diagnostics_use_only_fixed_phases(): void
    {
        phase('single_migration');
        $this->assertSame('single_migration', safeFailure()['phase']);
        unset($GLOBALS['ev_incremental_phase']);
        $this->expectException(RuntimeException::class);
        phase('password-or-signed-link');
    }

    public static function rejectedEntrypoint(): array
    {
        return [
            ['EV_APPROVAL_VERIFIED', ''], ['DB_DATABASE', 'pwdwork_ev_rehearsal'],
            ['DB_HOST', 'other.aivencloud.com'], ['DB_PORT', '3306'],
            ['EMAIL_VERIFICATION_ENABLED', 'true'], ['GITHUB_RUN_ATTEMPT', '2'],
        ];
    }

    #[DataProvider('rejectedEntrypoint')]
    public function test_actual_entrypoint_refuses_unapproved_or_wrong_targets_before_source_or_connection(string $key, string $value): void
    {
        $output = tmpfile();
        $environment = array_replace($this->environment(), [
            'DB_PASSWORD' => 'synthetic-do-not-log', 'APP_KEY' => 'synthetic-do-not-log', $key => $value,
        ]);
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/.github/scripts/email-verification-incremental.php'],
            [0 => ['pipe', 'r'], 1 => $output, 2 => $output], $pipes, null, $environment);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $this->assertSame(1, proc_close($process));
        rewind($output);
        $raw = stream_get_contents($output);
        fclose($output);
        $receipt = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('FAIL', $receipt['status']);
        $this->assertSame('authorization', $receipt['phase']);
        $this->assertStringNotContainsString('synthetic-do-not-log', $raw);
    }

    public function test_actual_reviewed_migration_compiles_to_only_the_three_approved_mysql_alters_without_a_connection(): void
    {
        $connection = new MySqlConnection(fn () => throw new RuntimeException('database_connections_forbidden'), 'offline_only', '',
            ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'version' => '8.4.11']);
        $previous = Schema::getFacadeRoot();
        try {
            Schema::swap($connection->getSchemaBuilder());
            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php';
            $queries = $connection->pretend(fn () => $migration->up());
            $this->assertSame(DDL, array_column($queries, 'query'));
            $this->assertSame([[], [], []], array_column($queries, 'bindings'));
        } finally {
            if ($previous !== null) {
                Schema::swap($previous);
            } else {
                Schema::clearResolvedInstance('db.schema');
            }
        }
    }

    public static function serverCases(): array
    {
        return [['pwdwork_prod', '8.4.11', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'TLS_AES_256_GCM_SHA384', true],
            ['mysql', '8.4.11', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'TLS_AES_256_GCM_SHA384', false],
            ['pwdwork_prod', '8.4.10', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'TLS_AES_256_GCM_SHA384', false],
            ['pwdwork_prod', '8.4.11', 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee', 'TLS_AES_256_GCM_SHA384', false],
            ['pwdwork_prod', '8.4.11', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', '', false]];
    }

    #[DataProvider('serverCases')]
    public function test_actual_connected_identity_and_negotiated_tls_are_checked(string $database, string $version, string $uuid, string $cipher, bool $accepted): void
    {
        // Mock PDO has no constructor/connection, so this cannot access any database.
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['query', 'exec'])->getMock();
        $pdo->method('query')->willReturnCallback(function (string $sql) use ($database, $version, $uuid, $cipher) {
            $statement = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['fetchColumn', 'fetch'])->getMock();
            $statement->method('fetchColumn')->willReturn(match ($sql) {
                'SELECT DATABASE()' => $database, 'SELECT VERSION()' => $version, 'SELECT @@server_uuid' => $uuid,
                default => false,
            });
            $statement->method('fetch')->willReturn(['Ssl_cipher', $cipher]);

            return $statement;
        });
        $pdo->expects($accepted ? $this->exactly(2) : $this->never())->method('exec');
        if (! $accepted) {
            $this->expectException(RuntimeException::class);
        }
        verifyServer($pdo, $this->environment()['EXPECTED_SERVER_UUID']);
        if ($accepted) {
            $this->addToAssertionCount(1);
        }
    }
}
