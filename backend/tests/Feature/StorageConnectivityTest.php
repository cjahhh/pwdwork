<?php

namespace Tests\Feature;

use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorageConnectivityTest extends TestCase
{
    private array $objects;

    private array $operations = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.debug' => false, 'filesystems.connectivity_test_enabled' => true, 'filesystems.resume_disk' => 's3',
            'filesystems.disks.s3' => [
                'driver' => 's3', 'visibility' => 'private', 'key' => 'PRIVATE_KEY', 'secret' => 'PRIVATE_SECRET',
                'region' => 'test-region', 'bucket' => 'private-test-bucket',
                'endpoint' => 'https://s3.test-region.backblazeb2.com',
            ],
        ]);
        $this->objects = ['resumes/123.pdf' => 'PRIVATE_EXISTING_RESUME', '_pwdwork-storage-test/existing.txt' => 'PRIVATE_EXISTING_TEST'];
    }

    private function disk(string $fault = ''): void
    {
        $disk = Mockery::mock(AwsS3V3Adapter::class);
        $client = Mockery::mock(S3Client::class);
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);
        $track = function (string $method, string $key): void {
            $this->assertMatchesRegularExpression('~^_pwdwork-storage-test/[a-f0-9]{48}\.txt$~', $key);
            $this->operations[] = [$method, $key];
        };
        $disk->shouldReceive('fileExists')->andReturnUsing(function ($key) use ($fault, $track) {
            $track('fileExists', $key);
            if ($fault === 'collision') {
                return true;
            }
            if ($fault === 'preflight_throw') {
                throw new \RuntimeException('PRIVATE_PROVIDER_BODY');
            }

            return isset($this->objects[$key]);
        });
        $disk->shouldReceive('put')->andReturnUsing(function ($key, $content, $options) use ($fault, $track) {
            $track('put', $key);
            $this->assertSame(['visibility' => 'private', 'ContentType' => 'text/plain'], $options);
            $this->assertLessThan(100, strlen($content));
            if ($fault === 'upload_false') {
                return false;
            }
            if ($fault === 'upload_throw') {
                throw new \RuntimeException('PRIVATE_CREDENTIALS');
            }
            $this->objects[$key] = $content;
            if ($fault === 'upload_ambiguous') {
                throw new \RuntimeException('PRIVATE_TIMEOUT_AFTER_SUCCESS');
            }

            return true;
        });
        $disk->shouldReceive('getVisibility')->andReturnUsing(function ($key) use ($fault, $track) {
            $track('getVisibility', $key);

            return $fault === 'visibility_public' ? 'public' : 'private';
        });
        $disk->shouldReceive('get')->andReturnUsing(function ($key) use ($fault, $track) {
            $track('get', $key);
            if ($fault === 'read_throw') {
                throw new \RuntimeException('PRIVATE_READ_ERROR');
            }

            return $fault === 'read_mismatch' ? 'PRIVATE_WRONG_CONTENT' : $this->objects[$key];
        });
        $disk->shouldReceive('getClient')->andReturn($client);
        $disk->shouldReceive('getConfig')->andReturn(['bucket' => 'private-test-bucket']);
        $disk->shouldReceive('path')->andReturnUsing(fn ($key) => 'configured-root/'.$key);
        $client->shouldReceive('headObject')->andReturnUsing(function ($params) use ($fault, $track) {
            $this->assertSame('private-test-bucket', $params['Bucket']);
            $this->assertStringStartsWith('configured-root/', $params['Key']);
            $track('headObject', substr($params['Key'], strlen('configured-root/')));
            if ($fault === 'head_throw') {
                throw new \RuntimeException('PRIVATE_METADATA_ERROR');
            }

            return new Result($fault === 'missing_version' ? [] : ['VersionId' => 'PRIVATE_NEW_TEST_VERSION']);
        });
        $client->shouldReceive('deleteObject')->andReturnUsing(function ($params) use ($fault, $track) {
            $this->assertSame('PRIVATE_NEW_TEST_VERSION', $params['VersionId']);
            $this->assertSame('private-test-bucket', $params['Bucket']);
            $key = substr($params['Key'], strlen('configured-root/'));
            $track('deleteObject', $key);
            if ($fault === 'delete_throw') {
                throw new \RuntimeException('PRIVATE_DELETE_ERROR');
            }
            if ($fault !== 'delete_noop') {
                unset($this->objects[$key]);
            }

            return new Result;
        });
        // No exists(), listings, URL creation, or unversioned B2 deletion is allowed.
        $disk->shouldNotReceive('exists', 'files', 'allFiles', 'directories', 'allDirectories', 'url', 'temporaryUrl', 'delete');
    }

    public function test_success_is_private_round_trips_and_permanently_deletes_only_its_new_version(): void
    {
        $this->disk();
        $this->artisan('pwdwork:storage-test')
            ->expectsOutput('PASS storage-test.enabled')->expectsOutput('PASS storage-test.configuration')
            ->expectsOutput('PASS storage-test.adapter')->expectsOutput('PASS storage-test.disk')
            ->expectsOutput('PASS storage-test.object')->expectsOutput('PASS storage-test.upload')
            ->expectsOutput('PASS storage-test.visibility')->expectsOutput('PASS storage-test.read')
            ->expectsOutput('PASS storage-test.cleanup')->expectsOutput('PASS storage-test.result')->assertSuccessful();
        $this->assertSame(['resumes/123.pdf' => 'PRIVATE_EXISTING_RESUME', '_pwdwork-storage-test/existing.txt' => 'PRIVATE_EXISTING_TEST'], $this->objects);
        $this->assertCount(1, array_unique(array_column($this->operations, 1)));
    }

    public function test_disabled_is_safe_by_default_and_never_resolves_a_disk(): void
    {
        config(['filesystems.connectivity_test_enabled' => false]);
        Storage::shouldReceive('disk')->never();
        $this->artisan('pwdwork:storage-test')->expectsOutput('FAIL storage-test.enabled')
            ->expectsOutput('FAIL storage-test.result')->assertFailed();
    }

    public static function unsafeConfigurations(): array
    {
        return [
            'local' => ['filesystems.resume_disk', 'local'],
            'public' => ['filesystems.disks.s3.visibility', 'public'],
            'wrong driver' => ['filesystems.disks.s3.driver', 'local'],
            'debug' => ['app.debug', true],
            'no key' => ['filesystems.disks.s3.key', ''],
            'no secret' => ['filesystems.disks.s3.secret', ''],
            'no bucket' => ['filesystems.disks.s3.bucket', ''],
            'no region' => ['filesystems.disks.s3.region', ''],
            'http' => ['filesystems.disks.s3.endpoint', 'http://storage.example.test'],
            'url credentials' => ['filesystems.disks.s3.endpoint', 'https://PRIVATE_USER@storage.example.test'],
        ];
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_unsafe_configuration_fails_before_storage_access(string $name, mixed $value): void
    {
        config([$name => $value]);
        Storage::shouldReceive('disk')->never();
        $this->artisan('pwdwork:storage-test')->expectsOutput('FAIL storage-test.configuration')
            ->expectsOutput('FAIL storage-test.result')->assertFailed();
    }

    public static function storageFailures(): array
    {
        return [
            'false upload' => ['upload_false', 'upload', true],
            'thrown upload' => ['upload_throw', 'upload', true],
            'ambiguous upload' => ['upload_ambiguous', 'upload', true],
            'public object' => ['visibility_public', 'visibility', true],
            'read exception' => ['read_throw', 'read', true],
            'content mismatch' => ['read_mismatch', 'read', true],
            'metadata exception' => ['head_throw', 'cleanup', false],
            'delete exception' => ['delete_throw', 'cleanup', false],
            'delete had no effect' => ['delete_noop', 'cleanup', false],
            'missing B2 version' => ['missing_version', 'cleanup', false],
        ];
    }

    #[DataProvider('storageFailures')]
    public function test_failures_are_sanitized_and_cleanup_is_attempted(string $fault, string $stage, bool $cleaned): void
    {
        $this->disk($fault);
        $command = $this->artisan('pwdwork:storage-test');
        if ($stage !== 'cleanup') {
            $command->expectsOutput('FAIL storage-test.'.$stage);
        }
        $command->expectsOutput(($cleaned ? 'PASS' : 'FAIL').' storage-test.cleanup')
            ->expectsOutput('FAIL storage-test.result')->doesntExpectOutputToContain('PRIVATE_')->assertFailed()->run();
        $this->assertSame('PRIVATE_EXISTING_RESUME', $this->objects['resumes/123.pdf']);
        $this->assertSame('PRIVATE_EXISTING_TEST', $this->objects['_pwdwork-storage-test/existing.txt']);
        $this->assertSame($cleaned ? 2 : 3, count($this->objects));
    }

    public function test_collision_never_reads_overwrites_or_deletes_the_existing_key(): void
    {
        $this->disk('collision');
        $this->artisan('pwdwork:storage-test')->expectsOutput('FAIL storage-test.object')->expectsOutput('FAIL storage-test.result')->assertFailed();
        $this->assertSame(['fileExists'], array_column($this->operations, 0));
    }

    public function test_preflight_error_never_writes_or_deletes(): void
    {
        $this->disk('preflight_throw');
        $this->artisan('pwdwork:storage-test')->expectsOutput('FAIL storage-test.object')->expectsOutput('FAIL storage-test.result')->assertFailed();
        $this->assertSame(['fileExists'], array_column($this->operations, 0));
    }
}
