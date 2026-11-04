<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Container\Attributes\Config;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;

/**
 * Opens AMQP connections from `microservice.bus.connection`, over TLS when `secure` is set.
 */
class ConnectionFactory
{
    /** @param  array<string, mixed>  $config  Host, port, credentials, vhost, heartbeat, timeouts and TLS options. */
    public function __construct(
        #[Config('microservice.bus.connection', [])] private readonly array $config = [],
    ) {
    }

    /**
     * @param  int|null  $heartbeat  Overrides the configured heartbeat.
     * @param  float|null  $readWriteTimeout  Overrides the I/O timeout, derived from the heartbeat by default.
     */
    public function make(?int $heartbeat = null, ?float $readWriteTimeout = null): AbstractConnection
    {
        return AMQPConnectionFactory::create($this->connectionConfig($heartbeat, $readWriteTimeout));
    }

    /**
     * The php-amqplib configuration a connection is opened with.
     */
    public function connectionConfig(?int $heartbeat = null, ?float $readWriteTimeout = null): AMQPConnectionConfig
    {
        $settings = $this->config;
        $heartbeat ??= (int) ($settings['heartbeat'] ?? 60);
        $timeout = $readWriteTimeout ?? (float) ($settings['read_write_timeout'] ?? $heartbeat * 2 + 10);

        $config = new AMQPConnectionConfig;
        $config->setHost((string) ($settings['host'] ?? '127.0.0.1'));
        $config->setPort((int) ($settings['port'] ?? 5672));
        $config->setUser((string) ($settings['user'] ?? 'guest'));
        $config->setPassword((string) ($settings['password'] ?? 'guest'));
        $config->setVhost((string) ($settings['vhost'] ?? '/'));
        $config->setConnectionTimeout((float) ($settings['connection_timeout'] ?? 10));
        $config->setReadTimeout($timeout);
        $config->setWriteTimeout($timeout);
        $config->setHeartbeat($heartbeat);
        $config->setKeepalive((bool) ($settings['keepalive'] ?? true));

        if (! empty($settings['secure'])) {
            $tls = (array) ($settings['ssl'] ?? []);

            $config->setIsSecure(true);
            $config->setSslCaCert($tls['cafile'] ?? null);
            $config->setSslCert($tls['local_cert'] ?? null);
            $config->setSslKey($tls['local_pk'] ?? null);
            $config->setSslPassPhrase($tls['passphrase'] ?? null);
            $config->setSslVerify((bool) ($tls['verify_peer'] ?? true));
            $config->setSslVerifyName((bool) ($tls['verify_peer_name'] ?? true));
        }

        return $config;
    }
}
