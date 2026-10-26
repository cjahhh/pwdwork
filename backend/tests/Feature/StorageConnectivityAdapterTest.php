<?php

namespace Tests\Feature;

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class StorageConnectivityAdapterTest extends TestCase
{
    public function test_real_laravel_adapter_uses_only_exact_key_requests_and_versioned_cleanup_without_network(): void
    {
        $commands = [];
        $notFound = fn () => new S3Exception('PRIVATE_PROVIDER_BODY', new Command('HeadObject'), ['response' => new Response(404)]);
        $responses = [
            $notFound(), new Result(['VersionId' => 'synthetic-version']), new Result(['Grants' => []]),
            new Result(['Body' => Utils::streamFor("PWDWork fictional storage connectivity probe.\n")]),
            new Result(['VersionId' => 'synthetic-version']), new Result(['VersionId' => 'synthetic-version']),
            new Result, $notFound(),
        ];
        $handler = new MockHandler(array_map(function ($response) use (&$commands) {
            return function ($command) use ($response, &$commands) {
                $commands[] = $command;

                return $response;
            };
        }, $responses));
        config([
            'app.debug' => false, 'filesystems.resume_disk' => 's3', 'filesystems.connectivity_test_enabled' => true,
            'filesystems.disks.s3' => [
                'driver' => 's3', 'visibility' => 'private', 'key' => 'synthetic-key', 'secret' => 'synthetic-secret',
                'bucket' => 'synthetic-bucket', 'region' => 'us-west-test', 'endpoint' => 'https://s3.example.test',
                'root' => 'configured-root', 'use_path_style_endpoint' => true, 'handler' => $handler,
            ],
        ]);
        // A real SDK/adapter stack with a terminal MockHandler: no HTTP request can escape.
        $output = new BufferedOutput;
        $code = app(Kernel::class)->call('pwdwork:storage-test', [], $output);
        $this->assertSame(0, $code, $output->fetch());
        $this->assertSame(['HeadObject', 'PutObject', 'GetObjectAcl', 'GetObject', 'HeadObject', 'HeadObject', 'DeleteObject', 'HeadObject'], array_map(fn ($command) => $command->getName(), $commands));
        $keys = [];
        foreach ($commands as $command) {
            $this->assertSame('synthetic-bucket', $command['Bucket']);
            $this->assertMatchesRegularExpression('~^configured-root/_pwdwork-storage-test/[a-f0-9]{48}\.txt$~', $command['Key']);
            $keys[] = $command['Key'];
        }
        $this->assertCount(1, array_unique($keys));
        $this->assertSame('private', $commands[1]['ACL']);
        $this->assertSame('synthetic-version', $commands[6]['VersionId']);
        $this->assertCount(0, $handler);
        $diskConfig = Storage::disk('s3')->getConfig();
        $this->assertFalse($diskConfig['debug']);
        $this->assertFalse($diskConfig['report']);
        $this->assertTrue($diskConfig['http']['verify']);
        $this->assertSame(10, $diskConfig['http']['timeout']);
    }
}
