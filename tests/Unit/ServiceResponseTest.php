<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Response as LaravelResponse;
use Jurager\Microservice\Client\ServiceResponse;
use Jurager\Microservice\JsonApi\CollectionDocument;
use Jurager\Microservice\JsonApi\ItemDocument;
use Jurager\Microservice\Tests\TestCase;

class ServiceResponseTest extends TestCase
{
    public function test_it_is_a_laravel_http_client_response(): void
    {
        $response = new ServiceResponse(new Response(201, ['X-Total' => '3'], '{"data":{"id":"1"}}'));

        $this->assertInstanceOf(LaravelResponse::class, $response);
        $this->assertSame(201, $response->status());
        $this->assertTrue($response->successful());
        $this->assertFalse($response->failed());
        $this->assertSame('1', $response->json('data.id'));
        $this->assertSame('3', $response->header('X-Total'));
    }

    public function test_from_wraps_a_laravel_response(): void
    {
        $laravel = new LaravelResponse(new Response(200, [], '{"a":1}'));

        $this->assertSame(1, ServiceResponse::from($laravel)->json('a'));
    }

    public function test_document_is_empty_for_a_body_that_is_not_a_json_object(): void
    {
        $this->assertSame([], (new ServiceResponse(new Response(200, [], 'not json')))->document());
        $this->assertSame([], (new ServiceResponse(new Response(200, [], '"string"')))->document());
    }

    public function test_collection_and_item_read_json_api_documents(): void
    {
        $collection = (new ServiceResponse(new Response(200, [], '{"data":[{"id":"1","type":"products"}],"meta":{"total":1}}')))->collection();
        $item = (new ServiceResponse(new Response(200, [], '{"data":{"id":"7","type":"products"}}')))->item();

        $this->assertInstanceOf(CollectionDocument::class, $collection);
        $this->assertSame(1, $collection->total());
        $this->assertInstanceOf(ItemDocument::class, $item);
        $this->assertSame('7', $item->data()->id);
    }

    public function test_passthrough_keeps_status_body_and_headers_but_not_hop_by_hop_ones(): void
    {
        $response = (new ServiceResponse(new Response(404, ['X-Trace' => 'a', 'Transfer-Encoding' => 'chunked', 'Connection' => 'keep-alive'], '{"errors":[]}')))->passthrough();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('{"errors":[]}', $response->getContent());
        $this->assertSame('a', $response->headers->get('X-Trace'));
        $this->assertSame('application/vnd.api+json', $response->headers->get('Content-Type'));
        $this->assertFalse($response->headers->has('Transfer-Encoding'));
        $this->assertFalse($response->headers->has('Connection'));
    }
}
