<?php

use App\Support\StorageConnectivityStartup;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;

// Optional background CLI process only. Never register an HTTP route or connect at build time.
if (getenv('PWDWORK_STORAGE_TEST') !== 'true') {
    exit(0);
}

ini_set('display_errors', '0');
ini_set('log_errors', '0');

try {
    require '/var/www/backend/vendor/autoload.php';

    exit(StorageConnectivityStartup::run(
        true,
        '/tmp/pwdwork-storage-test.attempted',
        function (): string {
            $app = require '/var/www/backend/bootstrap/app.php';
            $kernel = $app->make(Kernel::class);
            $kernel->bootstrap();
            $output = new BufferedOutput;
            $kernel->call('pwdwork:storage-test', ['--no-ansi' => true, '--no-interaction' => true], $output);

            return $output->fetch();
        },
    ));
} catch (Throwable) {
    echo "FAIL storage-test.startup\n";
    exit(1);
}
