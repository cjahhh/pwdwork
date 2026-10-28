<?php

namespace Tests\Unit;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pwdwork\EmailIncrementalLab\RestoreComparisonFailure;

use function Pwdwork\EmailIncrementalLab\definitionParts;
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
            $statement->method('fetch')->willReturn([$fixture['tables'][0]['TABLE_NAME'], $fixture['ddl']]);

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

    public function test_reordered_final_ddl_clauses_move_commas_and_are_not_silently_accepted(): void
    {
        $before = $this->fixture();
        $after = $before;
        $lines = explode("\n", $after['ddl']);
        [$lines[2], $lines[3]] = [$lines[3], $lines[2]];
        $lines[2] .= ',';
        $lines[3] = rtrim($lines[3], ',');
        $after['ddl'] = implode("\n", $lines);
        try {
            requireRestored($this->snapshot($before['ddl']), $this->snapshot($after['ddl']), $this->pdo($before), $this->pdo($after));
            $this->fail('Raw DDL line order differences still require review');
        } catch (RestoreComparisonFailure $error) {
            $this->assertFalse($error->comparison['checks']['definition']);
            $this->assertFalse($error->comparison['checks']['definition_line_set']);
            $this->assertTrue($error->comparison['checks']['definition_parse']);
            $this->assertFalse($error->comparison['checks']['definition_tokens']);
            $this->assertTrue($error->comparison['checks']['definition_clause_set']);
            $this->assertTrue($error->comparison['checks']['definition_indexes']);
            $this->assertTrue($error->comparison['checks']['indexes']);
        }
    }

    private static function definitionFixture(): string
    {
        return <<<'SQL'
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(20) DEFAULT 'synthetic-private, (default)  ON DELETE RESTRICT',
  `computed` int GENERATED ALWAYS AS ((`id` + 1)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `synthetic_index` (`status`(10),`id`) INVISIBLE,
  CONSTRAINT `synthetic_fk` FOREIGN KEY (`id`) REFERENCES `sessions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `synthetic_check` CHECK ((`id` > 0)) /*!80016 NOT ENFORCED */
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='synthetic-private  comment'
SQL;
    }

    public function test_final_foreign_key_reordering_reproduces_all_supplied_comparison_outcomes(): void
    {
        $before = $this->fixture();
        $before['ddl'] = "CREATE TABLE `users` (\n  `id` bigint unsigned NOT NULL,\n  PRIMARY KEY (`id`),\n  CONSTRAINT `synthetic_a` FOREIGN KEY (`id`) REFERENCES `sessions` (`id`) ON DELETE RESTRICT,\n  CONSTRAINT `synthetic_b` FOREIGN KEY (`id`) REFERENCES `jobs` (`id`) ON DELETE RESTRICT\n) ENGINE=InnoDB AUTO_INCREMENT=2";
        $after = $before;
        $lines = explode("\n", $after['ddl']);
        [$lines[3], $lines[4]] = [$lines[4].',', rtrim($lines[3], ',')];
        $after['ddl'] = implode("\n", $lines);
        $left = $this->snapshot($before['ddl']);
        $right = $this->snapshot($after['ddl']);
        $left['users']['records']['rows'] = $right['users']['records']['rows'] = 0;
        try {
            requireRestored($left, $right, $this->pdo($before), $this->pdo($after));
            $this->fail('A reproduced possibility must not be accepted as the established runtime cause');
        } catch (RestoreComparisonFailure $error) {
            foreach ($error->comparison['checks'] as $category => $outcome) {
                $this->assertSame(! in_array($category, ['definition', 'definition_line_set', 'definition_tokens'], true), $outcome, $category);
            }
            $this->assertSame(0, $error->comparison['before_rows']);
            $this->assertSame(0, $error->comparison['after_rows']);
        }
    }

    public function test_whitespace_outside_quotes_is_classified_but_raw_restore_still_fails(): void
    {
        $before = $this->fixture();
        $after = $before;
        $after['ddl'] = str_replace("\n  ", "\r\n\t", $before['ddl']);
        $this->assertSame(definitionParts($before['ddl']), definitionParts($after['ddl']));
        try {
            requireRestored($this->snapshot($before['ddl']), $this->snapshot($after['ddl']), $this->pdo($before), $this->pdo($after));
            $this->fail('Diagnostic equivalence must not bypass exact restore validation');
        } catch (RestoreComparisonFailure $error) {
            $this->assertFalse($error->comparison['checks']['definition']);
            $this->assertTrue($error->comparison['checks']['definition_tokens']);
        }
    }

    public static function definitionChanges(): array
    {
        $ddl = self::definitionFixture();
        $changes = [
            ['bigint unsigned', 'int unsigned', 'definition_columns'],
            ['(default)  ON', '(default) ON', 'definition_columns'],
            ['DEFAULT \'synthetic-private, (default)  ON DELETE RESTRICT\'', 'DEFAULT NULL', 'definition_columns'],
            ['(`id` + 1)', '(`id` + 2)', 'definition_columns'],
            ['PRIMARY KEY (`id`)', 'PRIMARY KEY (`status`)', 'definition_primary_key'],
            ['UNIQUE KEY', 'KEY', 'definition_indexes'],
            ['(`status`(10),`id`)', '(`id`,`status`(10))', 'definition_indexes'],
            ['`status`(10)', '`status`(5)', 'definition_indexes'],
            ['INVISIBLE', 'VISIBLE', 'definition_indexes'],
            ['`synthetic_index`', '`different_index`', 'definition_indexes'],
            ['`synthetic_fk`', '`different_fk`', 'definition_foreign_keys'],
            ['REFERENCES `sessions`', 'REFERENCES `jobs`', 'definition_foreign_keys'],
            ['ON DELETE RESTRICT,', 'ON DELETE CASCADE,', 'definition_foreign_keys'],
            ['(`id` > 0)', '(`id` >= 0)', 'definition_checks'],
            ['/*!80016 NOT ENFORCED */', '/*!80016 ENFORCED */', 'definition_checks'],
            ['ENGINE=InnoDB', 'ENGINE=MyISAM', 'definition_table_options'],
            ['utf8mb4_unicode_ci', 'utf8mb4_bin', 'definition_table_options'],
            ['AUTO_INCREMENT=2', 'AUTO_INCREMENT=3', 'definition_auto_increment_option'],
            ['private  comment', 'private comment', 'definition_table_options'],
            ['CREATE TABLE `users`', 'CREATE TABLE `jobs`', 'definition_header'],
        ];

        return array_map(fn ($change) => [$ddl, str_replace($change[0], $change[1], $ddl), $change[2]], $changes);
    }

    #[DataProvider('definitionChanges')]
    public function test_meaningful_ddl_changes_have_precise_categories_and_remain_failures(string $ddl, string $changed, string $category): void
    {
        $left = definitionParts($ddl);
        $right = definitionParts($changed);
        $this->assertNotSame($left[$category], $right[$category]);
        $this->assertNotSame($left['definition_tokens'], $right['definition_tokens']);
        $this->assertNotSame($left['definition_clause_set'], $right['definition_clause_set']);
        $before = $this->fixture();
        $after = $before;
        $before['ddl'] = $ddl;
        $after['ddl'] = $changed;
        try {
            requireRestored($this->snapshot($ddl), $this->snapshot($changed), $this->pdo($before), $this->pdo($after));
            $this->fail('Equal supplemental metadata cannot bypass a meaningful DDL difference');
        } catch (RestoreComparisonFailure $error) {
            $this->assertTrue($error->comparison['checks']['definition_parse']);
            $this->assertFalse($error->comparison['checks'][$category]);
            $this->assertTrue($error->comparison['checks']['row_count']);
            $this->assertTrue($error->comparison['checks']['data_fingerprint']);
            $this->assertStringNotContainsString('synthetic', json_encode($error->comparison));
            $this->assertStringNotContainsString('CREATE TABLE', json_encode($error->comparison));
        }
    }

    public function test_fk_default_action_hint_preserves_names_targets_and_nondefault_actions(): void
    {
        $ddl = self::definitionFixture();
        $left = definitionParts($ddl);
        foreach (['', ' ON DELETE NO ACTION'] as $replacement) {
            $right = definitionParts(str_replace(' ON DELETE RESTRICT,', $replacement.',', $ddl));
            $this->assertNotSame($left['definition_foreign_keys'], $right['definition_foreign_keys']);
            $this->assertSame($left['definition_fk_default_actions'], $right['definition_fk_default_actions']);
            // The same keywords inside a quoted default must never be removed.
            $this->assertSame($left['definition_columns'], $right['definition_columns']);
        }
        foreach ([' ON DELETE CASCADE', ' ON DELETE SET NULL'] as $replacement) {
            $right = definitionParts(str_replace(' ON DELETE RESTRICT,', $replacement.',', $ddl));
            $this->assertNotSame($left['definition_fk_default_actions'], $right['definition_fk_default_actions']);
        }
        $right = definitionParts(str_replace('REFERENCES `sessions`', 'REFERENCES `jobs`', $ddl));
        $this->assertNotSame($left['definition_fk_default_actions'], $right['definition_fk_default_actions']);
    }

    public function test_quoted_delimiters_comments_and_duplicate_clauses_are_preserved(): void
    {
        $ddl = "CREATE TABLE `users` (`odd``name` varchar(20) DEFAULT 'it\\'s, (a)', `other` varchar(20) DEFAULT 'it''s', CHECK ((1 > 0)) /* (, ) */) ENGINE=InnoDB";
        $parts = definitionParts($ddl);
        $this->assertCount(2, $parts['definition_columns']);
        $this->assertCount(1, $parts['definition_checks']);
        $this->assertContains("'it\\'s, (a)'", $parts['definition_columns'][0]);
        $this->assertContains("'it''s'", $parts['definition_columns'][1]);
        $this->assertContains('/* (, ) */', $parts['definition_checks'][0]);
        $duplicated = str_replace('CHECK ((1 > 0))', 'CHECK ((1 > 0)), CHECK ((1 > 0))', $ddl);
        $this->assertNotSame($parts['definition_clause_set'], definitionParts($duplicated)['definition_clause_set']);
        $reordered = str_replace("`odd``name` varchar(20) DEFAULT 'it\\'s, (a)', `other` varchar(20) DEFAULT 'it''s'",
            "`other` varchar(20) DEFAULT 'it''s', `odd``name` varchar(20) DEFAULT 'it\\'s, (a)'", $ddl);
        $this->assertSame($parts['definition_clause_set'], definitionParts($reordered)['definition_clause_set']);
        $this->assertNotSame($parts['definition_columns'], definitionParts($reordered)['definition_columns']);
    }

    public function test_unsupported_definition_keeps_original_metadata_diagnostics_and_fails_closed(): void
    {
        $before = $this->fixture();
        $after = $before;
        $after['ddl'] = 'synthetic-private-unsupported-definition';
        try {
            requireRestored($this->snapshot($before['ddl']), $this->snapshot($after['ddl']), $this->pdo($before), $this->pdo($after));
            $this->fail('Unsupported diagnostic grammar must not pass');
        } catch (RestoreComparisonFailure $error) {
            $this->assertFalse($error->comparison['checks']['definition']);
            $this->assertFalse($error->comparison['checks']['definition_parse']);
            $this->assertTrue($error->comparison['checks']['column_definitions']);
            $this->assertTrue($error->comparison['checks']['foreign_keys']);
            $this->assertStringNotContainsString('synthetic-private', json_encode($error->comparison));
        }
    }

    private function columnFixture(string $roleSql = 'varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL'): array
    {
        $fixture = $this->fixture();
        $fixture['tables'][0]['TABLE_NAME'] = 'accommodation_request_events';
        $fixture['columns'] = [];
        foreach (['id', 'accommodation_request_id', 'actor_id', 'actor_role'] as $i => $name) {
            $fixture['columns'][] = ['COLUMN_NAME' => $name, 'ORDINAL_POSITION' => (string) ($i + 1),
                'COLUMN_TYPE' => $i === 3 ? 'varchar(20)' : 'bigint unsigned', 'IS_NULLABLE' => 'NO',
                'COLUMN_DEFAULT' => null, 'EXTRA' => $i === 0 ? 'auto_increment' : '',
                'CHARACTER_SET_NAME' => $i === 3 ? 'utf8mb4' : null,
                'COLLATION_NAME' => $i === 3 ? 'utf8mb4_unicode_ci' : null, 'GENERATION_EXPRESSION' => ''];
        }
        $fixture['ddl'] = "CREATE TABLE `accommodation_request_events` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `accommodation_request_id` bigint unsigned NOT NULL,\n  `actor_id` bigint unsigned NOT NULL,\n  `actor_role` ".$roleSql.",\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return $fixture;
    }

    private function columnReport(array $before, array $after): array
    {
        $table = 'accommodation_request_events';
        try {
            requireRestored([$table => $this->snapshot($before['ddl'])['users']],
                [$table => $this->snapshot($after['ddl'])['users']], $this->pdo($before), $this->pdo($after));
            $this->fail('Column diagnostics must never relax raw DDL equality');
        } catch (RestoreComparisonFailure $error) {
            $this->assertFalse($error->comparison['checks']['definition']);
            $this->assertFalse($error->comparison['checks']['definition_columns']);
            $this->assertTrue($error->comparison['checks']['definition_snapshot_before']);
            $this->assertTrue($error->comparison['checks']['definition_snapshot_after']);

            return $error->comparison;
        }
    }

    public function test_mysql_explicit_charset_example_identifies_first_column_without_accepting_restore(): void
    {
        $before = $this->columnFixture();
        $after = $this->columnFixture('varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $report = $this->columnReport($before, $after);
        $this->assertTrue($report['checks']['column_definitions']);
        $this->assertTrue($report['checks']['column_defaults']);
        $column = $report['column'];
        $this->assertSame(4, $column['ordinal']);
        $this->assertSame('actor_role', $column['before_name']);
        $this->assertSame('actor_role', $column['after_name']);
        $this->assertSame('collation', $column['before_token_kind']);
        $this->assertSame('character_set', $column['after_token_kind']);
        foreach ($column['checks'] as $name => $outcome) {
            $this->assertSame($name !== 'tokens_equal', $outcome, $name);
        }
        $json = json_encode($report);
        foreach (['CREATE TABLE', 'utf8mb4', 'varchar', 'synthetic-private', hash('sha256', $before['ddl'])] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public static function realColumnChanges(): array
    {
        return [
            ['varchar(21) COLLATE utf8mb4_unicode_ci NOT NULL', ['COLUMN_TYPE' => 'varchar(21)'], 'data_type'],
            ['varchar(20) COLLATE utf8mb4_unicode_ci NULL', ['IS_NULLABLE' => 'YES'], 'nullability'],
            ["varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'synthetic-private-value'", ['COLUMN_DEFAULT' => 'synthetic-private-value'], 'default'],
            ['varchar(20) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL', ['CHARACTER_SET_NAME' => 'latin1', 'COLLATION_NAME' => 'latin1_bin'], 'character_set'],
            ['varchar(20) COLLATE utf8mb4_bin NOT NULL', ['COLLATION_NAME' => 'utf8mb4_bin'], 'collation'],
            ['varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL INVISIBLE', ['EXTRA' => 'INVISIBLE'], 'extra'],
            ["varchar(20) GENERATED ALWAYS AS (concat('synthetic-private', `id`)) VIRTUAL", ['GENERATION_EXPRESSION' => "concat('synthetic-private', `id`)", 'EXTRA' => 'VIRTUAL GENERATED'], 'generation'],
            ["varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'synthetic-private-comment'", [], null],
            ['varchar(20) CHARACTER SET latin1 COLLATE utf8mb4_unicode_ci NOT NULL', [], null],
            ['VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL', [], null],
        ];
    }

    #[DataProvider('realColumnChanges')]
    public function test_real_column_changes_remain_rejected_and_cannot_match_the_charset_hint(string $sql, array $metadata, ?string $category): void
    {
        $before = $this->columnFixture();
        $after = $this->columnFixture($sql);
        $after['columns'][3] = array_replace($after['columns'][3], $metadata);
        $report = $this->columnReport($before, $after);
        $this->assertFalse($report['column']['checks']['charset_spelling_only']);
        $this->assertFalse($report['column']['checks']['all_columns_charset_spelling']);
        if ($category !== null) {
            $this->assertFalse($report['column']['checks'][$category]);
        }
        $this->assertStringNotContainsString('synthetic-private', json_encode($report));
    }

    public function test_first_charset_hint_does_not_hide_a_later_real_column_change(): void
    {
        $before = $this->columnFixture();
        $after = $this->columnFixture('varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        foreach (['before' => &$before, 'after' => &$after] as $which => &$fixture) {
            $fixture['ddl'] = str_replace('  PRIMARY KEY', '  `version` int unsigned NOT NULL DEFAULT '.($which === 'before' ? '1' : '2').",\n  PRIMARY KEY", $fixture['ddl']);
            $fixture['columns'][] = ['COLUMN_NAME' => 'version', 'ORDINAL_POSITION' => '5', 'COLUMN_TYPE' => 'int unsigned',
                'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => $which === 'before' ? '1' : '2', 'EXTRA' => '',
                'CHARACTER_SET_NAME' => null, 'COLLATION_NAME' => null, 'GENERATION_EXPRESSION' => ''];
        }
        unset($fixture);
        $report = $this->columnReport($before, $after);
        $this->assertTrue($report['column']['checks']['charset_spelling_only']);
        $this->assertFalse($report['column']['checks']['all_columns_charset_spelling']);
    }

    public function test_parser_metadata_misalignment_and_unknown_names_are_safely_reported(): void
    {
        $before = $this->columnFixture();
        $after = $this->columnFixture('varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $after['ddl'] = str_replace('`actor_role`', '`synthetic-private-name`', $after['ddl']);
        $report = $this->columnReport($before, $after);
        $this->assertNull($report['column']['after_name']);
        $this->assertTrue($report['column']['checks']['parser_metadata_before']);
        $this->assertFalse($report['column']['checks']['parser_metadata_after']);
        $this->assertFalse($report['column']['checks']['column_order']);
        $this->assertFalse($report['column']['checks']['charset_spelling_only']);
        $this->assertStringNotContainsString('synthetic-private', json_encode($report));
    }

    public function test_real_column_reordering_matches_its_metadata_but_remains_a_failure(): void
    {
        $before = $this->columnFixture();
        $after = $before;
        $lines = explode("\n", $after['ddl']);
        [$lines[3], $lines[4]] = [$lines[4], $lines[3]];
        $after['ddl'] = implode("\n", $lines);
        [$after['columns'][2], $after['columns'][3]] = [$after['columns'][3], $after['columns'][2]];
        $after['columns'][2]['ORDINAL_POSITION'] = '3';
        $after['columns'][3]['ORDINAL_POSITION'] = '4';
        $report = $this->columnReport($before, $after);
        $this->assertSame('actor_id', $report['column']['before_name']);
        $this->assertSame('actor_role', $report['column']['after_name']);
        $this->assertTrue($report['column']['checks']['parser_metadata_before']);
        $this->assertTrue($report['column']['checks']['parser_metadata_after']);
        $this->assertFalse($report['column']['checks']['column_order']);
        $this->assertFalse($report['column']['checks']['all_columns_charset_spelling']);
    }

    public function test_diagnostic_reread_drift_is_identified_without_replacing_the_original_snapshot(): void
    {
        $before = $this->columnFixture();
        $after = $this->columnFixture('varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
        $table = 'accommodation_request_events';
        try {
            requireRestored([$table => $this->snapshot('synthetic-private-earlier-ddl')['users']],
                [$table => $this->snapshot($after['ddl'])['users']], $this->pdo($before), $this->pdo($after));
            $this->fail('A diagnostic reread must never replace a mismatched historical fingerprint');
        } catch (RestoreComparisonFailure $error) {
            $this->assertFalse($error->comparison['checks']['definition_snapshot_before']);
            $this->assertTrue($error->comparison['checks']['definition_snapshot_after']);
            $this->assertTrue($error->comparison['column']['checks']['charset_spelling_only']);
            $this->assertStringNotContainsString('synthetic-private', json_encode($error->comparison));
        }
    }

    public function test_quoted_charset_keywords_comments_and_generated_expressions_are_not_removed(): void
    {
        foreach (["varchar(20) DEFAULT 'CHARACTER SET utf8mb4'", 'varchar(20) /* CHARACTER SET utf8mb4 */',
            'varchar(20) GENERATED ALWAYS AS (CHARACTER SET utf8mb4) VIRTUAL',
            'varchar(20) CHARACTER SET latin1', 'varchar(20) CHARACTER SET utf8mb4 CHARACTER SET utf8mb4'] as $sql) {
            $tokens = definitionParts('CREATE TABLE `users` (`id` '.$sql.') ENGINE=InnoDB')['definition_columns'][0];
            $this->assertSame($tokens, \Pwdwork\EmailIncrementalLab\columnCharsetSpelling($tokens, ['CHARACTER_SET_NAME' => 'utf8mb4']));
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
