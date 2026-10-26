<?php

namespace App\Support;

use Throwable;

class StorageConnectivityStartup
{
    public static function run(bool $enabled, string $marker, callable $probe): int
    {
        if (! $enabled) {
            return 0;
        }
        // Atomic, local, per-container attempt marker. Never store a key or credentials.
        $handle = @fopen($marker, 'x');
        if ($handle === false) {
            if (is_file($marker)) {
                return 0;
            }
            echo "FAIL storage-test.startup\n";

            return 1;
        }
        fclose($handle);
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $output = $probe();
        } catch (Throwable) {
            $output = "FAIL storage-test.startup\n";
        } finally {
            // Discard incidental bootstrap/provider output, including unexpected exceptions.
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }
        $passed = false;
        $failed = false;
        foreach (explode("\n", is_string($output) ? $output : '') as $line) {
            $line = rtrim($line, "\r");
            if (preg_match('/\A(PASS|FAIL) storage-test\.(enabled|configuration|adapter|disk|object|upload|visibility|read|cleanup|result|startup)\z/', $line)) {
                echo $line."\n";
                $failed = $failed || str_starts_with($line, 'FAIL ');
                $passed = $passed || $line === 'PASS storage-test.result';
            }
        }
        if (! $passed && ! $failed) {
            echo "FAIL storage-test.startup\n";
        }

        return $passed && ! $failed ? 0 : 1;
    }
}
