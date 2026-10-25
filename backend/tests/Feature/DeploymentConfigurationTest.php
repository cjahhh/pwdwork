<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use PDO;
use Tests\TestCase;

class DeploymentConfigurationTest extends TestCase
{
    public function test_a_configured_mysql_ca_enables_certificate_verification_for_both_drivers(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('PDO MySQL is required to verify TLS driver options.');
        }

        $repository = Env::getRepository();
        $original = $repository->get('MYSQL_ATTR_SSL_CA');
        try {
            $repository->set('MYSQL_ATTR_SSL_CA', '/test-only/aiven-ca.pem');
            $connections = (require config_path('database.php'))['connections'];
            foreach (['mysql', 'mariadb'] as $driver) {
                $this->assertSame('/test-only/aiven-ca.pem', $connections[$driver]['options'][PDO::MYSQL_ATTR_SSL_CA]);
                $this->assertTrue($connections[$driver]['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
            }

            $repository->clear('MYSQL_ATTR_SSL_CA');
            $connections = (require config_path('database.php'))['connections'];
            foreach (['mysql', 'mariadb'] as $driver) {
                $this->assertArrayNotHasKey(PDO::MYSQL_ATTR_SSL_CA, $connections[$driver]['options']);
                $this->assertArrayNotHasKey(PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT, $connections[$driver]['options']);
            }
        } finally {
            $original === null ? $repository->clear('MYSQL_ATTR_SSL_CA') : $repository->set('MYSQL_ATTR_SSL_CA', $original);
        }
    }

    public function test_existing_boot_health_endpoint_does_not_require_a_database(): void
    {
        config(['database.default' => 'unconfigured-test-connection']);
        $this->get('/up')->assertOk();
    }

    public function test_s3_adapter_is_available_without_exposing_private_resumes(): void
    {
        $this->assertTrue(class_exists(AwsS3V3Adapter::class));
        $this->assertSame('private', config('filesystems.disks.s3.visibility'));
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.s3'));
    }
}
