<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use InvalidArgumentException;
use Jurager\Microservice\MicroserviceServiceProvider;
use Jurager\Microservice\Support\Peers;
use Jurager\Microservice\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PeersConfigTest extends TestCase
{
    /** defineEnvironment runs after the provider registered, so register it again with the config under test. */
    private function register(array $peers, array $services = []): void
    {
        $this->app['config']->set('microservice.peers', $peers);
        $this->app['config']->set('microservice.manifest.services', $services);

        $this->app->register(MicroserviceServiceProvider::class, force: true);
    }

    public function test_peers_are_normalized_to_a_name_url_map(): void
    {
        $this->register(['api' => ' https://api.example.com/ ', 'shop' => 'http://shop.example.org:8080']);

        $this->assertSame(['api' => 'https://api.example.com', 'shop' => 'http://shop.example.org:8080'], Peers::all());
    }

    public function test_entry_with_unset_url_is_skipped(): void
    {
        $this->register(['api' => null, 'shop' => '']);

        $this->assertSame([], Peers::all());
    }

    public function test_peers_extend_the_services_a_gateway_pulls(): void
    {
        $this->register(['api' => 'https://api.example.com'], ['pim', 'oms']);

        $this->assertSame(['pim', 'oms', 'api'], Peers::withServices());
    }

    public function test_peer_listed_as_manifest_service_fails_fast(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->register(['pim' => 'https://pim.example.com'], ['pim']);
    }

    #[DataProvider('malformed')]
    public function test_malformed_peer_fails_fast(array $peers): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->register($peers);
    }

    public static function malformed(): array
    {
        return [
            'not http' => [['api' => 'ftp://api.example.com']],
            'bare host' => [['api' => 'api.example.com']],
            'numeric name' => [['https://api.example.com']],
        ];
    }
}
