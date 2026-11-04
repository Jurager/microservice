<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Bus\ConnectionFactory;
use PHPUnit\Framework\TestCase;

class ConnectionFactoryTest extends TestCase
{
    public function test_settings_reach_the_connection_config(): void
    {
        $config = (new ConnectionFactory(['host' => 'rabbit', 'port' => 5673, 'vhost' => '/shop', 'heartbeat' => 30]))->connectionConfig();

        $this->assertSame('rabbit', $config->getHost());
        $this->assertSame(5673, $config->getPort());
        $this->assertSame('/shop', $config->getVhost());
        $this->assertSame(30, $config->getHeartbeat());
        $this->assertSame(70.0, $config->getReadTimeout());
        $this->assertFalse($config->isSecure());
    }

    public function test_a_secure_connection_uses_tls_with_the_given_certificates(): void
    {
        $config = (new ConnectionFactory([
            'secure' => true,
            'ssl' => ['cafile' => '/etc/ssl/ca.pem', 'verify_peer_name' => false],
        ]))->connectionConfig();

        $this->assertTrue($config->isSecure());
        $this->assertSame('/etc/ssl/ca.pem', $config->getSslCaCert());
        $this->assertTrue($config->getSslVerify());
        $this->assertFalse($config->getSslVerifyName());
    }

    public function test_a_probe_may_shorten_the_io_timeout(): void
    {
        $this->assertSame(10.0, (new ConnectionFactory)->connectionConfig(readWriteTimeout: 10)->getReadTimeout());
    }
}
