<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;

/** Temporary production diagnostics. Never inspect messages, SQL or bindings. */
final class DatabaseFailureDiagnostics
{
    public static function context(QueryException $exception): array
    {
        $info = $exception->errorInfo;
        if (! is_array($info) && $exception->getPrevious() instanceof PDOException) {
            $info = $exception->getPrevious()->errorInfo;
        }

        $state = $info[0] ?? $exception->getCode();
        $state = is_string($state) && preg_match('/\A[0-9A-Z]{5}\z/', $state) === 1 ? $state : null;
        $driver = $info[1] ?? null;
        $driver = (is_int($driver) || (is_string($driver) && preg_match('/\A[1-9][0-9]{0,4}\z/', $driver) === 1))
            && (int) $driver > 0 && (int) $driver <= 65535 ? (int) $driver : null;

        return [
            'sqlstate' => $state,
            'mysql_driver_code' => $driver,
            'category' => match ($driver) {
                2002, 2003 => 'connection_failure',
                2005 => 'name_resolution_failure',
                2006, 2013 => 'connection_lost',
                2026 => 'tls_failure',
                1045 => 'authentication_failure',
                1044, 1142, 1143 => 'permission_denied',
                1049 => 'database_not_found',
                1146 => 'table_not_found',
                1054 => 'column_not_found',
                1040, 1203 => 'connection_limit',
                default => match (true) {
                    $state !== null && str_starts_with($state, '08') => 'connection_failure',
                    $state === '23000' => 'integrity_constraint',
                    $state === '40001' => 'transaction_conflict',
                    default => 'unclassified_database_failure',
                },
            },
        ];
    }
}
