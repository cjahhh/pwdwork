<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter as LaravelS3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use RuntimeException;
use Throwable;

class StorageConnectivityTest extends Command
{
    protected $signature = 'pwdwork:storage-test';

    protected $description = 'Opt-in private storage probe using only one random fictional test object';

    private const PREFIX = '_pwdwork-storage-test/';

    public function handle(): int
    {
        $stage = 'enabled';
        $disk = null;
        $key = null;
        $owned = false;
        $passed = false;
        try {
            $this->require(config('filesystems.connectivity_test_enabled') === true);
            $this->result($stage, true);
            $stage = 'configuration';
            $settings = config('filesystems.disks.s3', []);
            $this->require(config('filesystems.resume_disk') === 's3'
                && config('app.debug') === false
                && ($settings['driver'] ?? null) === 's3'
                && ($settings['visibility'] ?? null) === 'private');
            foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $field) {
                $this->require(is_string($settings[$field] ?? null) && trim($settings[$field]) !== '');
            }
            $endpoint = parse_url($settings['endpoint']);
            $this->require(filter_var($settings['endpoint'], FILTER_VALIDATE_URL) !== false
                && $endpoint !== false && ($endpoint['scheme'] ?? null) === 'https'
                && ! isset($endpoint['user']) && ! isset($endpoint['pass'])
                && ! isset($endpoint['query']) && ! isset($endpoint['fragment']));
            $this->result($stage, true);

            $stage = 'adapter';
            $this->require(class_exists(AwsS3V3Adapter::class) && class_exists(LaravelS3Adapter::class) && class_exists(S3Client::class));
            $this->result($stage, true);
            // Only this CLI process changes these diagnostic/safety options. Credentials,
            // endpoint, bucket, region and addressing mode remain the existing settings.
            config([
                'filesystems.disks.s3.throw' => true, 'filesystems.disks.s3.report' => false,
                'filesystems.disks.s3.debug' => false, 'filesystems.disks.s3.stats' => false,
                'filesystems.disks.s3.http.debug' => false, 'filesystems.disks.s3.http.verify' => true,
                'filesystems.disks.s3.http.connect_timeout' => 5, 'filesystems.disks.s3.http.timeout' => 10,
                'filesystems.disks.s3.retries' => 0,
            ]);
            $stage = 'disk';
            $disk = Storage::disk(config('filesystems.resume_disk'));
            $this->require($disk instanceof LaravelS3Adapter);
            $this->result($stage, true);
            $stage = 'object';
            $key = self::PREFIX.bin2hex(random_bytes(24)).'.txt';
            // fileExists uses HEAD on this exact key. exists() can fall back to a listing.
            $this->require($disk->fileExists($key) === false);
            $owned = true;
            $this->result($stage, true);
            $content = "PWDWork fictional storage connectivity probe.\n";
            $stage = 'upload';
            $this->require($disk->put($key, $content, ['visibility' => 'private', 'ContentType' => 'text/plain']) === true);
            $this->result($stage, true);
            $stage = 'visibility';
            $this->require($disk->getVisibility($key) === 'private');
            $this->result($stage, true);
            $stage = 'read';
            $this->require($disk->get($key) === $content);
            $this->result($stage, true);
            $passed = true;
        } catch (Throwable) {
            $this->result($stage, false);
        } finally {
            if ($owned) {
                try {
                    // Also clean up a write that succeeded remotely but timed out locally.
                    if ($disk->fileExists($key)) {
                        $client = $disk->getClient();
                        $location = ['Bucket' => $disk->getConfig()['bucket'], 'Key' => $disk->path($key)];
                        $metadata = $client->headObject($location);
                        $version = $metadata['VersionId'] ?? null;
                        if (is_string($version) && $version !== '' && $version !== 'null') {
                            // B2 is versioned: deleting only by name leaves a historical file.
                            // Delete solely the version at the fresh key; never enumerate versions.
                            $client->deleteObject([...$location, 'VersionId' => $version]);
                        } else {
                            // Never mistake a B2 delete marker for permanent test-file cleanup.
                            $this->require(! str_ends_with(strtolower($endpoint['host']), '.backblazeb2.com'));
                            $this->require($disk->delete($key) === true);
                        }
                    }
                    $this->require($disk->fileExists($key) === false);
                    $this->result('cleanup', true);
                } catch (Throwable) {
                    $passed = false;
                    $this->result('cleanup', false);
                }
            }
        }
        $this->result('result', $passed);

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Storage test stage failed.');
        }
    }

    private function result(string $stage, bool $passed): void
    {
        $this->line(($passed ? 'PASS' : 'FAIL').' storage-test.'.$stage);
    }
}
