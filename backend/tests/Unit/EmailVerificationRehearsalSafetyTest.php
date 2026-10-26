<?php

namespace Tests\Unit;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Pwdwork\EmailRehearsal\authorize;
use function Pwdwork\EmailRehearsal\digestRows;
use function Pwdwork\EmailRehearsal\encode;
use function Pwdwork\EmailRehearsal\main;
use function Pwdwork\EmailRehearsal\manifest;
use function Pwdwork\EmailRehearsal\partialState;
use function Pwdwork\EmailRehearsal\readStatements;
use function Pwdwork\EmailRehearsal\safeError;
use function Pwdwork\EmailRehearsal\target;

class EmailVerificationRehearsalSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/.github/scripts/email-verification-rehearsal.php';
    }

    private function authorization(): array
    {
        $sha = str_repeat('a', 40);

        return [[
            'GITHUB_ACTIONS' => 'true', 'RUNNER_OS' => 'Linux', 'GITHUB_REPOSITORY' => 'cjahhh/pwdwork',
            'GITHUB_REF' => 'refs/heads/security/email-verification-mysql-rehearsal',
            'GITHUB_EVENT_NAME' => 'push', 'GITHUB_SHA' => $sha,
        ], [
            'ref' => 'refs/heads/security/email-verification-mysql-rehearsal', 'after' => $sha,
            'head_commit' => ['id' => $sha, 'message' => '[run-email-verification-rehearsal]'],
            'forced' => false, 'deleted' => false,
        ]];
    }

    public function test_only_the_explicit_testing_branch_push_is_authorized(): void
    {
        [$environment, $event] = $this->authorization();
        authorize($environment, $event);
        $this->addToAssertionCount(1);
    }

    public static function invalidEnvironments(): array
    {
        return [
            ['GITHUB_ACTIONS', 'false'], ['RUNNER_OS', 'Windows'], ['GITHUB_REPOSITORY', 'other/pwdwork'],
            ['GITHUB_REF', 'refs/heads/main'], ['GITHUB_REF', 'refs/heads/security/email-verification-mysql-rehearsal-extra'],
            ['GITHUB_REF', 'refs/tags/security/email-verification-mysql-rehearsal'],
            ['GITHUB_EVENT_NAME', 'workflow_dispatch'], ['GITHUB_EVENT_NAME', 'pull_request_target'],
            ['GITHUB_SHA', '$(private-command)'], ['GITHUB_SHA', str_repeat('b', 40)],
        ];
    }

    #[DataProvider('invalidEnvironments')]
    public function test_wrong_repository_branch_runner_event_or_sha_is_rejected(string $name, string $value): void
    {
        [$environment, $event] = $this->authorization();
        $environment[$name] = $value;
        $this->expectException(RuntimeException::class);
        authorize($environment, $event);
    }

    public static function invalidEvents(): array
    {
        return [
            ['forced', true], ['deleted', true], ['ref', 'refs/heads/main'], ['after', str_repeat('b', 40)],
            ['message', 'ordinary update'], ['message', 'prefix [run-email-verification-rehearsal]'],
            ['message', '[run-email-verification-rehearsal] $(secret-command)'], ['id', str_repeat('b', 40)],
        ];
    }

    #[DataProvider('invalidEvents')]
    public function test_unsafe_or_unconfirmed_pushes_are_rejected(string $name, mixed $value): void
    {
        [$environment, $event] = $this->authorization();
        if (in_array($name, ['id', 'message'], true)) {
            $event['head_commit'][$name] = $value;
        } else {
            $event[$name] = $value;
        }
        $this->expectException(RuntimeException::class);
        authorize($environment, $event);
    }

    public static function rejectedDatabases(): array
    {
        return [['pwdwork_prod'], ['mysql'], ['defaultdb'], ['aiven.example/pwdwork_ev_rehearsal'], ['pwdwork_ev_rehearsal;DROP DATABASE mysql'], ['pwdwork_ev_rehearsal_extra']];
    }

    #[DataProvider('rejectedDatabases')]
    public function test_no_production_or_arbitrary_database_name_is_permitted(string $database): void
    {
        $this->expectException(RuntimeException::class);
        target($database);
    }

    public static function recoveryStates(): array
    {
        return [[0, 0, 'not_applied'], [1, 0, 'manual_forward_repair_required'], [2, 0, 'manual_forward_repair_required'],
            [3, 0, 'manual_ledger_reconciliation_required'], [3, 1, 'complete'], [3, 2, 'inconsistent_stop'], [0, 1, 'inconsistent_stop']];
    }

    #[DataProvider('recoveryStates')]
    public function test_partial_success_never_becomes_automatic_resume(int $columns, int $records, string $expected): void
    {
        $this->assertSame($expected, partialState($columns, $records));
    }

    public function test_manifest_attests_all_existing_migrations_and_excludes_the_new_one_from_initialization(): void
    {
        $root = dirname(__DIR__, 3);
        $reviewed = json_decode(file_get_contents($root.'/.github/scripts/email-verification-rehearsal-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $old = manifest($root, $reviewed);
        $this->assertCount(15, $old);
        $this->assertNotContains('2026_10_26_000001_add_email_ownership_verification_to_users.php', $old);
    }

    public function test_changed_reviewed_migration_is_rejected_before_use(): void
    {
        $root = dirname(__DIR__, 3);
        $reviewed = json_decode(file_get_contents($root.'/.github/scripts/email-verification-rehearsal-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $reviewed['files']['backend/database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php'] = str_repeat('0', 64);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reviewed_file_changed');
        manifest($root, $reviewed);
    }

    public function test_manifest_cannot_read_an_environment_file(): void
    {
        $root = dirname(__DIR__, 3);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest_path_rejected');
        manifest($root, ['baseline_commit' => 'd495aa8eed7a683b3213f3f59440b9700adfedcb', 'files' => ['backend/.env' => str_repeat('0', 64)]]);
    }

    public function test_existing_verification_sql_is_read_only_and_not_the_fixture(): void
    {
        $count = 0;
        foreach (['preflight', 'postflight', 'original-users-digest'] as $file) {
            $count += count(readStatements(file_get_contents(dirname(__DIR__, 3).'/.github/sql/email-verification-'.$file.'.sql')));
        }
        $this->assertSame(23, $count);
    }

    public static function writingSql(): array
    {
        return [['DELETE FROM users;'], ['SELECT 1; DROP TABLE users;'], ['SELECT 1 INTO OUTFILE "/tmp/private";'], ['SELECT * FROM users FOR UPDATE;'], ['SET GLOBAL read_only=0;']];
    }

    #[DataProvider('writingSql')]
    public function test_verification_sql_rejects_writes_or_file_output(string $sql): void
    {
        $this->expectException(RuntimeException::class);
        readStatements($sql);
    }

    public function test_data_fingerprint_is_order_independent_but_detects_password_and_session_changes(): void
    {
        $before = [['id' => '1', 'password' => 'HASH_A', 'payload' => 'PAYLOAD_A'], ['id' => '2', 'password' => 'HASH_B', 'payload' => null]];
        $this->assertSame(digestRows($before), digestRows(array_reverse($before)));
        $after = $before;
        $after[0]['password'] = 'HASH_CHANGED';
        $this->assertNotSame(digestRows($before), digestRows($after));
        $after = $before;
        $after[0]['payload'] = 'PAYLOAD_CHANGED';
        $this->assertNotSame(digestRows($before), digestRows($after));
        $after = $before;
        $after[1]['payload'] = '';
        $this->assertNotSame(digestRows($before), digestRows($after));
        $this->assertStringNotContainsString('HASH_A', digestRows($before));
    }

    public function test_failure_evidence_excludes_messages_credentials_sql_and_trace(): void
    {
        $error = new PDOException('PRIVATE_PASSWORD signed-proof email-content');
        $error->errorInfo = ['HY000', 1045, 'PRIVATE_PASSWORD with user identity'];
        $result = safeError($error, 'apply');
        $this->assertSame(['status' => 'FAIL', 'phase' => 'apply', 'category' => 'check_failed', 'mysql_code' => null], $result);
        $this->assertStringNotContainsString('PRIVATE_PASSWORD', encode($result));
        $this->assertStringNotContainsString('signed-proof', encode($result));
        $this->assertSame('unknown', safeError($error, 'PRIVATE_PASSWORD')['phase']);
    }

    public function test_workflow_has_no_production_authorization_or_automatic_main_trigger(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/email-verification-mysql-rehearsal.yml');
        $this->assertStringContainsString('branches: [security/email-verification-mysql-rehearsal]', $workflow);
        $this->assertStringContainsString('contents: read', $workflow);
        foreach (['secrets.', 'id-token:', 'workflow_call:', 'pull_request:', 'workflow_run:', 'schedule:', 'environment:', 'actions: write', 'packages: write'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $workflow);
        }
        $this->assertStringContainsString('persist-credentials: false', $workflow);
        $this->assertStringContainsString('retention-days: 1', $workflow);
    }

    public static function rejectedOperations(): array
    {
        return [[['apply', 'pwdwork_prod']], [['PRIVATE_PASSWORD', 'pwdwork_ev_rehearsal']], [['apply']]];
    }

    #[DataProvider('rejectedOperations')]
    public function test_bad_cli_requests_fail_before_application_loading_or_stdin_access(array $arguments): void
    {
        $before = [];
        foreach (['EV_DISPOSABLE_CONTAINER' => 'true', 'EV_NETWORK_MODE' => 'none'] as $name => $value) {
            $before[$name] = getenv($name);
            putenv($name.'='.$value);
        }
        try {
            ob_start();
            $exit = main($arguments);
            $output = ob_get_clean();
            $this->assertSame(1, $exit);
            $this->assertSame('container_boundary', json_decode($output, true, flags: JSON_THROW_ON_ERROR)['phase']);
            $this->assertStringNotContainsString('PRIVATE_PASSWORD', $output);
            $this->assertStringNotContainsString('PDO', $output);
        } finally {
            foreach ($before as $name => $value) {
                $value === false ? putenv($name) : putenv($name.'='.$value);
            }
        }
    }
}
