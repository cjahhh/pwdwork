<?php

namespace Tests\Unit;

use App\Support\StorageConnectivityStartup;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StorageConnectivityStartupTest extends TestCase
{
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = sys_get_temp_dir().'/pwdwork-storage-test-'.bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        if (is_file($this->marker)) {
            unlink($this->marker);
        }
        parent::tearDown();
    }

    private function runProbe(bool $enabled, callable $probe): array
    {
        ob_start();
        try {
            $code = StorageConnectivityStartup::run($enabled, $this->marker, $probe);

            return [$code, ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }

    public function test_disabled_does_not_invoke_probe_create_marker_or_print(): void
    {
        $this->assertSame([0, ''], $this->runProbe(false, fn () => throw new \RuntimeException('Probe must not run')));
        $this->assertFileDoesNotExist($this->marker);
    }

    public function test_actual_startup_script_does_nothing_when_flag_is_disabled(): void
    {
        // No container paths or credentials are needed: the opt-in check runs first.
        $process = new Process([PHP_BINARY, base_path('../deploy/docker/storage-connectivity-test.php')], base_path(), ['PWDWORK_STORAGE_TEST' => 'false']);
        $this->assertSame(0, $process->run());
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_attempt_runs_only_once_even_after_failure(): void
    {
        $calls = 0;
        $probe = function () use (&$calls) {
            $calls++;

            return "FAIL storage-test.read\nFAIL storage-test.result\n";
        };
        $this->assertSame([1, "FAIL storage-test.read\nFAIL storage-test.result\n"], $this->runProbe(true, $probe));
        $this->assertSame([0, ''], $this->runProbe(true, $probe));
        $this->assertSame(1, $calls);
        $this->assertSame('', file_get_contents($this->marker));
    }

    public function test_bootstrap_output_and_exception_details_are_discarded(): void
    {
        $this->assertSame([1, "FAIL storage-test.startup\n"], $this->runProbe(true, function () {
            echo 'PRIVATE_CREDENTIALS_AND_FILE_CONTENTS';
            throw new \RuntimeException('PRIVATE_PROVIDER_BODY_AND_TRACE');
        }));
    }

    public function test_only_exact_stage_results_are_forwarded_and_success_requires_final_pass(): void
    {
        $this->assertSame([0, "PASS storage-test.read\nPASS storage-test.result\n"], $this->runProbe(true, function () {
            echo 'PRIVATE_BOOTSTRAP_OUTPUT';

            return "PRIVATE_URL\nPASS storage-test.read\nPASS storage-test.PRIVATE_SECRET\nPASS storage-test.result PRIVATE_DATA\nPASS storage-test.result\n";
        }));
    }

    public function test_missing_result_is_failure_without_dumping_unexpected_output(): void
    {
        $this->assertSame([1, "FAIL storage-test.startup\n"], $this->runProbe(true, fn () => 'PRIVATE_TRACE'));
    }

    public function test_a_reported_failure_cannot_be_overridden_by_a_later_pass(): void
    {
        $this->assertSame([1, "FAIL storage-test.cleanup\nPASS storage-test.result\n"], $this->runProbe(true, fn () => "FAIL storage-test.cleanup\nPASS storage-test.result\n"));
    }

    public function test_marker_creation_failure_is_safe_and_does_not_invoke_probe(): void
    {
        $this->marker .= '/missing-directory/marker';
        $this->assertSame([1, "FAIL storage-test.startup\n"], $this->runProbe(true, fn () => throw new \RuntimeException('Probe must not run')));
    }
}
