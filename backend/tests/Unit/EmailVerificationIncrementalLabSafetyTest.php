<?php

namespace Tests\Unit;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Pwdwork\EmailIncremental\failureDetails;
use function Pwdwork\EmailIncremental\phase;
use function Pwdwork\EmailIncremental\safeFailure;
use function Pwdwork\EmailIncrementalLab\diagnostic;
use function Pwdwork\EmailIncrementalLab\operation;

class EmailVerificationIncrementalLabSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/.github/scripts/email-verification-incremental.php';
        require_once dirname(__DIR__, 3).'/.github/scripts/email-verification-incremental-rehearsal.php';
        unset($GLOBALS['ev_lab_operation'], $GLOBALS['ev_lab_runner_failure'], $GLOBALS['ev_incremental_phase']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['ev_lab_operation'], $GLOBALS['ev_lab_runner_failure'], $GLOBALS['ev_incremental_phase']);
        parent::tearDown();
    }

    public static function nativeCodes(): array
    {
        return [[1045, 'mysql_permissions', 1045], [1049, 'mysql_schema', 1049], [1054, 'mysql_schema', 1054],
            [1205, 'mysql_lock', 1205], [2002, 'mysql_connectivity', 2002], [2026, 'tls', 2026], [99999, 'database', null]];
    }

    #[DataProvider('nativeCodes')]
    public function test_native_error_classification_never_emits_messages(int $code, string $category, ?int $expectedCode): void
    {
        $error = new PDOException('synthetic-secret-password-and-connection-url');
        $error->errorInfo = ['HY000', $code, 'synthetic-private-sql-and-file-content'];
        $this->assertSame(['category' => $category, 'mysql_code' => $expectedCode], failureDetails($error));
    }

    public function test_non_database_failures_use_fixed_categories_without_exception_text(): void
    {
        foreach ([['validation', new RuntimeException('synthetic-secret')], ['php_runtime', new \TypeError('synthetic-secret')],
            ['invalid_json', new \JsonException('synthetic-secret')], ['unexpected', new \Exception('synthetic-secret')]] as [$category, $error]) {
            $this->assertSame(['category' => $category, 'mysql_code' => null], failureDetails($error));
        }
    }

    public function test_runner_failure_phase_and_category_survive_the_lab_assertion(): void
    {
        $error = new PDOException('synthetic-secret');
        $error->errorInfo = ['HY000', 2026, 'synthetic-private-certificate-content'];
        phase('tls_connection');
        $GLOBALS['ev_lab_runner_failure'] = safeFailure($error);
        operation('preview_result');
        $this->assertSame(['operation' => 'preview_result', 'category' => 'tls',
            'runner_phase' => 'tls_connection', 'mysql_code' => 2026], diagnostic(new RuntimeException('synthetic-secret')));
    }

    public function test_preservation_failure_does_not_reuse_an_expected_runner_rejection(): void
    {
        phase('authorization');
        $GLOBALS['ev_lab_runner_failure'] = safeFailure(new RuntimeException('synthetic-secret'));
        operation('preservation');
        $this->assertSame(['operation' => 'preservation', 'category' => 'validation',
            'runner_phase' => null, 'mysql_code' => null], diagnostic(new RuntimeException('synthetic-private-row')));
    }

    public function test_unknown_runner_diagnostic_values_are_not_reflected(): void
    {
        foreach (['phase', 'category', 'mysql_code'] as $field) {
            $GLOBALS['ev_lab_runner_failure'] = array_replace(['phase' => 'tls_connection', 'category' => 'tls', 'mysql_code' => 2026],
                [$field => 'synthetic-secret-signed-url']);
            operation('preview_result');
            $result = diagnostic(new RuntimeException('synthetic-private-row'));
            $this->assertSame('validation', $result['category']);
            $this->assertStringNotContainsString('synthetic-', json_encode($result));
        }
    }

    public function test_subprocess_protocol_errors_cannot_echo_private_stderr(): void
    {
        operation('runner_output');
        $this->assertSame(['operation' => 'runner_output', 'category' => 'runner_protocol',
            'runner_phase' => null, 'mysql_code' => null], diagnostic(new RuntimeException('synthetic-private-stderr')));
    }

    public function test_unapproved_local_lab_entrypoint_reports_only_boundary_codes(): void
    {
        $output = tmpfile();
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/.github/scripts/email-verification-incremental-rehearsal.php', 'preview_readonly'],
            [0 => ['pipe', 'r'], 1 => $output, 2 => $output], $pipes, null,
            ['EV_DISPOSABLE_CONTAINER' => 'false', 'DB_PASSWORD' => 'synthetic-secret', 'APP_KEY' => 'synthetic-secret']);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $this->assertSame(1, proc_close($process));
        rewind($output);
        $raw = stream_get_contents($output);
        fclose($output);
        $this->assertSame(['status' => 'FAIL', 'phase' => 'disposable_runner_assertion', 'diagnostic' => [
            'operation' => 'container_boundary', 'category' => 'validation', 'runner_phase' => null, 'mysql_code' => null,
        ]], json_decode($raw, true, flags: JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('synthetic-secret', $raw);
    }
}
