<?php

namespace Tests\Unit;

use App\Support\DatabaseFailureDiagnostics;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatabaseFailureDiagnosticsTest extends TestCase
{
    public static function failures(): array
    {
        return [
            ['HY000', 2002, 'connection_failure'],
            ['HY000', 2003, 'connection_failure'],
            ['HY000', 2005, 'name_resolution_failure'],
            ['HY000', 2006, 'connection_lost'],
            ['HY000', 2013, 'connection_lost'],
            ['HY000', 2026, 'tls_failure'],
            ['28000', 1045, 'authentication_failure'],
            ['42000', 1044, 'permission_denied'],
            ['42000', 1142, 'permission_denied'],
            ['42000', 1143, 'permission_denied'],
            ['42000', 1049, 'database_not_found'],
            ['42S02', 1146, 'table_not_found'],
            ['42S22', 1054, 'column_not_found'],
            ['08004', 1040, 'connection_limit'],
            ['42000', 1203, 'connection_limit'],
            ['08006', null, 'connection_failure'],
            ['23000', 1062, 'integrity_constraint'],
            ['40001', 1213, 'transaction_conflict'],
            ['HY000', 9999, 'unclassified_database_failure'],
        ];
    }

    #[DataProvider('failures')]
    public function test_only_validated_codes_and_fixed_categories_are_returned(string $state, ?int $driver, string $category): void
    {
        $pdo = new PDOException('synthetic-password certificate contents SQL and connection details');
        $pdo->errorInfo = [$state, $driver, 'sensitive-driver-message'];
        $query = new QueryException('private-connection', 'select ? from private_table', ['private-binding'], $pdo);

        $this->assertSame([
            'sqlstate' => $state, 'mysql_driver_code' => $driver, 'category' => $category,
        ], DatabaseFailureDiagnostics::context($query));
    }

    public function test_malformed_fields_cannot_inject_sensitive_log_content(): void
    {
        foreach ([['password\nSQLSTATE', '2002 password'], [[], []], ['HY000', 65536], ['HY000', -1], ['HY000', true]] as [$state, $driver]) {
            $pdo = new PDOException('private-message');
            $pdo->errorInfo = [$state, $driver, 'private-driver-message'];
            $context = DatabaseFailureDiagnostics::context(new QueryException('private', 'private-sql', ['private-binding'], $pdo));
            $this->assertSame(is_string($state) && $state === 'HY000' ? 'HY000' : null, $context['sqlstate']);
            $this->assertNull($context['mysql_driver_code']);
            $this->assertSame('unclassified_database_failure', $context['category']);
            $this->assertCount(3, $context);
        }
    }

    public function test_numeric_driver_strings_are_normalized_and_missing_codes_remain_null(): void
    {
        $pdo = new PDOException('private-message');
        $pdo->errorInfo = ['HY000', '2026', 'private-driver-message'];
        $this->assertSame(2026, DatabaseFailureDiagnostics::context(new QueryException('private', 'private-sql', [], $pdo))['mysql_driver_code']);
        $pdo->errorInfo = null;
        $this->assertSame([
            'sqlstate' => null, 'mysql_driver_code' => null, 'category' => 'unclassified_database_failure',
        ], DatabaseFailureDiagnostics::context(new QueryException('private', 'private-sql', [], $pdo)));
    }
}
