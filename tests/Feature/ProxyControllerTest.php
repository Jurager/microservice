<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Jurager\Microservice\Http\Controllers\ProxyController;
use Jurager\Microservice\Tests\TestCase;

class ProxyControllerTest extends TestCase
{
    /** What the downstream service answers. */
    private mixed $answer;

    protected function setUp(): void
    {
        parent::setUp();

        config(['microservice.peers' => ['oms' => 'http://oms.test', 'pim' => 'http://pim.test']]);

        $this->answer(Http::response(['data' => 'proxied'], 200, ['Content-Type' => 'application/json']));
        Http::fake(fn () => $this->answer);
    }

    private function answer(mixed $response): void
    {
        $this->answer = $response;
    }

    /** The request the downstream service received. */
    private function forwarded(): Request
    {
        return Http::recorded()[0][0];
    }

    protected function defineRoutes($router): void
    {
        $route = $router->get('/api/orders', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'oms',
            '_service_uri' => '/api/orders',
            '_service_prefix' => 'oms',
        ]));

        $route = $router->post('/api/orders', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'oms',
            '_service_uri' => '/api/orders',
            '_service_prefix' => 'oms',
        ]));

        $route = $router->get('/api/products/{product}', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'pim',
            '_service_uri' => '/api/products/{product}',
            '_service_prefix' => 'pim',
        ]));

        $route = $router->get('/api/fallback', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'oms',
        ]));

        $route = $router->post('/api/raw-body', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'oms',
            '_service_uri' => '/api/raw-body',
            '_service_prefix' => 'oms',
        ]));

        $route = $router->post('/api/import', [ProxyController::class, 'handle']);
        $route->setAction(array_merge($route->getAction(), [
            '_service' => 'pim',
            '_service_uri' => '/api/import',
            '_service_prefix' => 'pim',
        ]));
    }

    public function test_proxies_get_request(): void
    {
        $this->getJson('/api/orders')->assertOk()->assertJson(['data' => 'proxied']);

        $this->assertSame('GET', $this->forwarded()->method());
        $this->assertSame('http://oms.test/api/orders', $this->forwarded()->url());
    }

    public function test_forwards_a_json_body_byte_for_byte(): void
    {
        $this->call('POST', '/api/orders', server: ['CONTENT_TYPE' => 'application/vnd.api+json'], content: '{"data":{"attributes":{},"price":1.0}}')->assertOk();

        $this->assertSame('POST', $this->forwarded()->method());
        $this->assertSame('{"data":{"attributes":{},"price":1.0}}', $this->forwarded()->body());
        $this->assertSame(['application/vnd.api+json'], $this->forwarded()->header('Content-Type'));
    }

    public function test_forwards_a_body_that_is_not_json_with_its_content_type(): void
    {
        $this->call('POST', '/api/raw-body', server: ['CONTENT_TYPE' => 'text/plain'], content: 'plain text body');

        $this->assertSame('plain text body', $this->forwarded()->body());
        $this->assertSame(['text/plain'], $this->forwarded()->header('Content-Type'));
    }

    public function test_forwards_form_fields_as_sent(): void
    {
        $this->call('POST', '/api/orders', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], content: 'name=Desk&tags%5B%5D=a&tags%5B%5D=b');

        $this->assertSame(['application/x-www-form-urlencoded'], $this->forwarded()->header('Content-Type'));
        parse_str($this->forwarded()->body(), $fields);
        $this->assertSame(['name' => 'Desk', 'tags' => ['a', 'b']], $fields);
    }

    public function test_post_with_empty_body_sends_none(): void
    {
        $this->call('POST', '/api/orders', server: ['CONTENT_TYPE' => 'application/json']);

        $this->assertSame('', $this->forwarded()->body());
    }

    public function test_resolves_path_with_route_parameters(): void
    {
        $this->getJson('/api/products/42')->assertOk();

        $this->assertSame('http://pim.test/api/products/42', $this->forwarded()->url());
    }

    public function test_a_route_parameter_cannot_change_the_downstream_path_or_query(): void
    {
        $this->getJson('/api/products/'.rawurlencode('1?admin=1#x'))->assertOk();

        $this->assertSame('http://pim.test/api/products/1%3Fadmin%3D1%23x', $this->forwarded()->url());
    }

    public function test_forwards_query_parameters(): void
    {
        $this->getJson('/api/orders?page=2&limit=10')->assertOk();

        $this->assertSame('http://oms.test/api/orders?page=2&limit=10', $this->forwarded()->url());
    }

    public function test_falls_back_to_request_path_when_service_uri_is_null(): void
    {
        $this->getJson('/api/fallback')->assertOk();

        $this->assertSame('http://oms.test/api/fallback', $this->forwarded()->url());
    }

    public function test_forwards_the_client_request_id_downstream(): void
    {
        $requestId = '550e8400-e29b-41d4-a716-446655440013';

        $this->postJson('/api/orders', ['product_id' => 1], ['X-Request-Id' => $requestId])->assertOk();

        $this->assertSame([$requestId], $this->forwarded()->header('X-Request-Id'));
    }

    public function test_a_write_without_a_client_request_id_gets_one_of_its_own(): void
    {
        $this->postJson('/api/orders', ['product_id' => 1])->assertOk();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->forwarded()->header('X-Request-Id')[0]);
    }

    public function test_filters_hop_by_hop_and_configured_headers(): void
    {
        config(['microservice.proxy.strip_headers' => ['X-Powered-By']]);

        $this->answer(Http::response('{"ok":true}', 200, [
            'Content-Type' => 'application/json',
            'Transfer-Encoding' => 'chunked',
            'Connection' => 'keep-alive',
            'X-Powered-By' => 'upstream',
            'X-Custom' => 'preserved',
        ]));

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertHeader('X-Custom', 'preserved')
            ->assertHeaderMissing('Transfer-Encoding')
            ->assertHeaderMissing('Connection')
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_a_client_error_is_passed_through_as_it_is(): void
    {
        $this->answer(Http::response('{"errors":[{"detail":"Name is required"}]}', 422, ['Content-Type' => 'application/vnd.api+json', 'Retry-After' => '5']));

        $this->postJson('/api/orders', [])
            ->assertStatus(422)
            ->assertHeader('Retry-After', '5')
            ->assertExactJson(['errors' => [['detail' => 'Name is required']]]);
    }

    public function test_a_failing_service_reaches_the_client_as_bad_gateway_without_its_details(): void
    {
        $this->answer(Http::response('{"errors":[{"detail":"SQLSTATE[42S02]"}]}', 500));

        $response = $this->getJson('/api/orders')->assertStatus(502);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_forwards_the_configured_client_headers(): void
    {
        $this->getJson('/api/orders', ['Accept-Language' => 'ru', 'X-Internal' => 'secret']);

        $this->assertSame(['ru'], $this->forwarded()->header('Accept-Language'));
        $this->assertFalse($this->forwarded()->hasHeader('X-Internal'));
    }

    public function test_forwards_proxy_headers_to_backend(): void
    {
        $this->getJson('/api/orders');

        foreach (['X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port', 'X-Gateway-Base-Url'] as $header) {
            $this->assertTrue($this->forwarded()->hasHeader($header), "$header is forwarded.");
        }

        $this->assertSame(['/oms'], $this->forwarded()->header('X-Forwarded-Prefix'));
    }

    public function test_omits_prefix_when_not_set(): void
    {
        $this->getJson('/api/fallback');

        $this->assertFalse($this->forwarded()->hasHeader('X-Forwarded-Prefix'));
    }

    public function test_proxies_multipart_with_file_and_fields(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.json', '{"products":[]}');
        $body = null;

        // Read while sending: the controller closes the file handles once the request is done.
        $this->answer(function (Request $request) use (&$body) {
            $body = $request->body();

            return Http::response();
        });

        $this->call('POST', '/api/import', ['import_type' => 'products'], [], ['import_file' => $file]);

        $this->assertStringContainsString('multipart/form-data', $this->forwarded()->header('Content-Type')[0]);
        $this->assertStringContainsString('name="import_type"', $body);
        $this->assertStringContainsString('filename="products.json"', $body);
        $this->assertStringContainsString('{"products":[]}', $body);
    }
}
