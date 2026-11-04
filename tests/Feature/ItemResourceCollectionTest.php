<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;
use Jurager\Microservice\JsonApi\Item;
use Jurager\Microservice\Tests\TestCase;

class ItemResourceCollectionTest extends TestCase
{
    public function test_items_serialize_as_a_json_api_resource_collection(): void
    {
        $request = Request::create('/regions');
        app()->instance('request', $request);

        $items = [
            new Item(['id' => '1', 'type' => 'regions', 'attributes' => ['name' => 'Tashkent']]),
            new Item(['id' => '2', 'type' => 'regions', 'attributes' => ['name' => 'Samarkand']]),
        ];

        $data = ItemRegionResource::collection($items)->toResponse($request)->getData(true);

        $this->assertSame(['1', '2'], array_column($data['data'], 'id'));
        $this->assertSame(['Tashkent', 'Samarkand'], array_column(array_column($data['data'], 'attributes'), 'name'));
    }
}

class ItemRegionResource extends JsonApiResource
{
    public function toId(Request $request): string
    {
        return $this->resource->id;
    }

    public function toType(Request $request): string
    {
        return $this->resource->type;
    }

    public function toAttributes(Request $request): array
    {
        return ['name' => $this['name']];
    }
}
