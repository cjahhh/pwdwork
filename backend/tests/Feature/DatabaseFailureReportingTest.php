<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

class DatabaseFailureReportingTest extends TestCase
{
    private function failure(): QueryException
    {
        $pdo = new PDOException('private-password private-key certificate SQL details');
        $pdo->errorInfo = ['HY000', 2026, 'private-driver-message'];

        return new QueryException('private-connection', 'select ? from private_table', ['private-binding'], $pdo);
    }

    private function expectSafeReport(): void
    {
        $this->app->instance('env', 'production');
        config(['app.debug' => false]);
        Log::shouldReceive('error')->once()->with('Database failure diagnostics.', [
            'sqlstate' => 'HY000', 'mysql_driver_code' => 2026, 'category' => 'tls_failure',
        ]);
    }

    public function test_production_reporter_stops_default_exception_logging(): void
    {
        $this->expectSafeReport();
        $this->app->make(ExceptionHandler::class)->report($this->failure());
    }

    public function test_api_error_response_stays_generic_with_safe_diagnostics(): void
    {
        $this->expectSafeReport();
        Route::get('/api/test-database-reporting', function () {
            throw $this->failure();
        });

        $this->getJson('/api/test-database-reporting')
            ->assertStatus(500)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertExactJson(['message' => 'The service is temporarily unavailable. Please try again later.']);
        $this->assertFalse(config('app.debug'));
    }

    public function test_other_production_exceptions_keep_the_existing_sanitized_report(): void
    {
        $this->app->instance('env', 'production');
        Log::shouldReceive('error')->once()->with(
            'Application exception; inspect protected monitoring and reproduce in staging.',
            ['exception_type' => \RuntimeException::class],
        );
        $this->app->make(ExceptionHandler::class)->report(new \RuntimeException('private-message'));
    }
}
