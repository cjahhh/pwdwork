<?php

namespace Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pwdwork\EmailIncrementalLab\RestoreComparisonFailure;

use function Pwdwork\EmailIncrementalLab\diagnostic;
use function Pwdwork\EmailIncrementalLab\fullSnapshot;
use function Pwdwork\EmailIncrementalLab\operation;
use function Pwdwork\EmailIncrementalLab\requireRestored;
use function Pwdwork\EmailIncrementalLab\restoreDifference;

class EmailVerificationRestoreComparisonTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/.github/scripts/email-verification-incremental.php';
        require_once dirname(__DIR__, 3).'/.github/scripts/email-verification-incremental-rehearsal.php';
        unset($GLOBALS['ev_lab_operation'], $GLOBALS['ev_lab_runner_failure']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['ev_lab_operation'], $GLOBALS['ev_lab_runner_failure']);
        parent::tearDown();
    }

    private function snapshot(string $ddl = 'synthetic-private-definition'): array
    {
        return ['users' => ['definition' => hash('sha256', $ddl),
            'records' => ['rows' => 1, 'data_sha256' => hash('sha256', 'synthetic-private-row')]]];
    }

    private function fixture(): array
    {
        return ['columns' => [['COLUMN_NAME' => 'id', 'COLUMN_DEFAULT' => null, 'COLUMN_TYPE' => 'bigint unsigned'],
            ['COLUMN_NAME' => 'status', 'COLUMN_DEFAULT' => 'synthetic-private-default', 'COLUMN_TYPE' => 'varchar(20)']],
            'indexes' => [['INDEX_NAME' => 'PRIMARY', 'COLUMN_NAME' => 'id', 'NON_UNIQUE' => '0']],
            'foreign' => [['CONSTRAINT_NAME' => 'synthetic_private_constraint', 'UPDATE_RULE' => 'RESTRICT']],
            'tables' => [['TABLE_NAME' => 'users', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci',
                'ROW_FORMAT' => 'Dynamic', 'CREATE_OPTIONS' => '', 'AUTO_INCREMENT' => '2']],
            'ddl' => "CREATE TABLE `users` (\n  `id` bigint unsigned NOT NULL,\n  UNIQUE KEY `synthetic_a` (`id`),\n  KEY `synthetic_b` (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=2"];
    }

    /** Mock PDO has no constructor or network connection. */
    private function pdo(array $fixture): PDO
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['prepare', 'query'])->getMock();
        $pdo->method('prepare')->willReturnCallback(function ($sql) use ($fixture) {
            $data = match (true) {
                str_contains($sql, 'information_schema.COLUMNS') => $fixture['columns'],
                str_contains($sql, 'information_schema.STATISTICS') => $fixture['indexes'],
                str_contains($sql, 'information_schema.KEY_COLUMN_USAGE') => $fixture['foreign'],
                str_contains($sql, 'information_schema.TABLES') => $fixture['tables'],
                default => throw new \RuntimeException('unexpected_offline_metadata_query'),
            };
            $statement = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['execute', 'fetchAll'])->getMock();
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchAll')->willReturn($data);

            return $statement;
        });
        $pdo->method('query')->willReturnCallback(function ($sql) use ($fixture) {
            $this->assertStringStartsWith('SHOW CREATE TABLE ', $sql);
            $statement = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->onlyMethods(['fetch'])->getMock();
            $statement->method('fetch')->willReturn(['users', $fixture['ddl']]);

            return $statement;
        });

        return $pdo;
    }

    public function test_identical_snapshot_passes_without_extra_metadata_reads(): void
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['prepare', 'query'])->getMock();
        $pdo->expects($this->never())->method('prepare');
        $pdo->expects($this->never())->method('query');
        requireRestored($this->snapshot(), $this->snapshot(), $pdo, $pdo);
        $this->assertNull(restoreDifference($this->snapshot(), $this->snapshot()));
    }

    public static function changedFingerprints(): array
    {
        return [['definition'], ['row_count'], ['data_fingerprint']];
    }

    #[DataProvider('changedFingerprints')]
    public function test_exact_failed_fingerprint_is_identified_without_disclosing_it(string $category): void
    {
        $before = $this->snapshot();
        $after = $before;
        if ($category === 'definition') {
            $after['users']['definition'] = hash('sha256', 'synthetic-private-other-definition');
        } elseif ($category === 'row_count') {
            $after['users']['records']['rows'] = 2;
        } else {
            $after['users']['records']['data_sha256'] = hash('sha256', 'synthetic-private-other-row');
        }
        $difference = restoreDifference($before, $after);
        $this->assertSame('users', $difference['table']);
        $this->assertFalse($difference['checks'][$category]);
        $this->assertSame(1, $difference['before_rows']);
        $this->assertStringNotContainsString('synthetic-private', json_encode($difference));
        $this->assertStringNotContainsString($before['users']['definition'], json_encode($difference));
        $this->assertStringNotContainsString($before['users']['records']['data_sha256'], json_encode($difference));
    }

    public static function metadataCategories(): array
    {
        return [['column_definitions'], ['column_defaults'], ['indexes'], ['foreign_keys'], ['table_options'], ['auto_increment_metadata']];
    }

    #[DataProvider('metadataCategories')]
    public function test_metadata_category_is_reported_and_equal_records_do_not_bypass_ddl_failure(string $category): void
    {
        $before = $this->fixture();
        $after = $before;
        match ($category) {
            'column_definitions' => $after['columns'][0]['COLUMN_TYPE'] = 'int unsigned',
            'column_defaults' => $after['columns'][1]['COLUMN_DEFAULT'] = 'synthetic-private-changed-default',
            'indexes' => $after['indexes'][0]['INDEX_NAME'] = 'synthetic_private_other_index',
            'foreign_keys' => $after['foreign'][0]['UPDATE_RULE'] = 'CASCADE',
            'table_options' => $after['tables'][0]['ROW_FORMAT'] = 'Compact',
            'auto_increment_metadata' => $after['tables'][0]['AUTO_INCREMENT'] = '3',
        };
        $after['ddl'] .= ' /* synthetic-private-ddl-difference */';
        operation('restore_compare');
        try {
            requireRestored($this->snapshot($before['ddl']), $this->snapshot($after['ddl']), $this->pdo($before), $this->pdo($after));
            $this->fail('Different raw DDL must be rejected');
        } catch (RestoreComparisonFailure $error) {
            $this->assertFalse($error->comparison['checks']['definition']);
            $this->assertFalse($error->comparison['checks'][$category]);
            $this->assertTrue($error->comparison['checks']['row_count']);
            $this->assertTrue($error->comparison['checks']['data_fingerprint']);
            $this->assertStringNotContainsString('synthetic-private', json_encode(diagnostic($error)));
            $this->assertStringNotContainsString('synthetic_private', json_encode(diagnostic($error)));
        }
    }

    public function test_reordered_ddl_lines_are_detected_and_not_silently_normalized(): void
    {
        $before = $this->fixture();
        $after = $before;
        $lines = explode("\n", $after['ddl']);
        [$lines[2], $lines[3]] = [$lines[3], $lines[2]];
        $after['ddl'] = implode("\n", $lines);
        try {
            requireRestored($this->snapshot($before['ddl']), $this->snapshot($after['ddl']), $this->pdo($before), $this->pdo($after));
            $this->fail('Raw DDL line order differences still require review');
        } catch (RestoreComparisonFailure $error) {
            $this->assertFalse($error->comparison['checks']['definition']);
            $this->assertTrue($error->comparison['checks']['definition_line_set']);
            $this->assertTrue($error->comparison['checks']['indexes']);
        }
    }

    public function test_missing_or_reordered_tables_remain_failures(): void
    {
        $before = $this->snapshot() + ['sessions' => $this->snapshot()['users']];
        $this->assertFalse(restoreDifference($before, $this->snapshot())['checks']['table_inventory']);
        $reordered = array_reverse($before, true);
        $difference = restoreDifference($before, $reordered);
        $this->assertTrue($difference['checks']['table_inventory']);
        $this->assertFalse($difference['checks']['table_order']);
    }

    public function test_unknown_table_names_are_not_reflected(): void
    {
        $before = ['synthetic-private-table-name' => $this->snapshot()['users']];
        $after = $before;
        $after['synthetic-private-table-name']['records']['rows'] = 2;
        $difference = restoreDifference($before, $after);
        $this->assertNull($difference['table']);
        $this->assertStringNotContainsString('synthetic-private', json_encode($difference));
    }

    public function test_snapshot_member_order_differences_remain_failures(): void
    {
        $before = $this->snapshot();
        $after = $before;
        $after['users']['records'] = array_reverse($after['users']['records'], true);
        $difference = restoreDifference($before, $after);
        $this->assertFalse($difference['checks']['snapshot_structure']);
        $this->assertTrue($difference['checks']['definition']);
        $this->assertTrue($difference['checks']['row_count']);
        $this->assertTrue($difference['checks']['data_fingerprint']);
    }

    public function test_migration_history_is_compared_in_full_even_at_equal_row_count(): void
    {
        $before = ['migrations' => $this->snapshot()['users']];
        $after = $before;
        $after['migrations']['records']['data_sha256'] = hash('sha256', 'synthetic-private-ledger-change');
        $difference = restoreDifference($before, $after);
        $this->assertTrue($difference['checks']['row_count']);
        $this->assertFalse($difference['checks']['migration_history']);
    }

    public function test_missing_primary_key_has_its_own_safe_comparison(): void
    {
        $fixture = $this->fixture();
        $fixture['indexes'] = [];
        try {
            fullSnapshot($this->pdo($fixture));
            $this->fail('Missing primary key must stop fingerprinting');
        } catch (RestoreComparisonFailure $error) {
            $this->assertSame(['table' => 'users', 'checks' => ['primary_key' => false],
                'before_rows' => null, 'after_rows' => null], $error->comparison);
        }
    }

    public function test_failed_supplemental_metadata_read_does_not_hide_the_original_mismatch(): void
    {
        $pdo = $this->getMockBuilder(PDO::class)->disableOriginalConstructor()->onlyMethods(['prepare'])->getMock();
        $pdo->method('prepare')->willThrowException(new PDOException('synthetic-private-query-or-credential'));
        try {
            requireRestored($this->snapshot(), $this->snapshot('synthetic-private-new-ddl'), $pdo, $pdo);
            $this->fail('Extra diagnostic failure must not turn restore into a pass');
        } catch (RestoreComparisonFailure $error) {
            $this->assertFalse($error->comparison['checks']['definition']);
            $this->assertFalse($error->comparison['checks']['metadata_read']);
            $this->assertStringNotContainsString('synthetic-private', json_encode($error->comparison));
        }
    }
}
