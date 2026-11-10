<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;
use Jurager\Microservice\Http\Middleware\JsonApiPagination;
use Jurager\Microservice\Http\Middleware\TrustPeer;
use Jurager\Microservice\Tests\TestCase;

class JsonApiPaginationTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::get('/api/items', static function (Request $request) {
            $paginator = new LengthAwarePaginator([], 30, 10, null, ['path' => LengthAwarePaginator::resolveCurrentPath()]);

            return ['path' => $paginator->path(), 'page' => $paginator->currentPage()];
        })->middleware(JsonApiPagination::class);

        Route::get('/api/products', static fn () => JsonResource::collection(new LengthAwarePaginator([['id' => 1]], 30, 10, 2, ['path' => LengthAwarePaginator::resolveCurrentPath()])))
            ->middleware(JsonApiPagination::class);

        Route::get('/api/plain-products', static fn () => JsonResource::collection(new LengthAwarePaginator([['id' => 1]], 30, 10, 2, ['path' => LengthAwarePaginator::resolveCurrentPath()])));

        Route::get('/api/plain-items', static function () {
            $paginator = new LengthAwarePaginator([], 30, 10, null, ['path' => LengthAwarePaginator::resolveCurrentPath()]);

            return ['path' => $paginator->path(), 'page' => $paginator->currentPage()];
        });

        Route::get('/api/peer-items', static function (Request $request) {
            $request->attributes->set(TrustPeer::ATTRIBUTE, 'gateway');

            return app(JsonApiPagination::class)->handle($request, static function () {
                $paginator = new LengthAwarePaginator([], 30, 10, null, ['path' => LengthAwarePaginator::resolveCurrentPath()]);

                return response()->json(['path' => $paginator->path()]);
            });
        });
    }

    public function test_reads_the_page_from_the_json_api_page_parameter(): void
    {
        $this->getJson('/api/items?page[number]=3')->assertJsonPath('page', 3);
    }

    public function test_ignores_the_gateway_url_header_from_an_unverified_caller(): void
    {
        $this->getJson('/api/items', ['X-Gateway-Base-Url' => 'https://evil.test/phish'])
            ->assertJsonPath('path', 'http://localhost/api/items');
    }

    public function test_uses_the_gateway_url_reported_by_a_verified_peer(): void
    {
        $this->getJson('/api/peer-items', ['X-Gateway-Base-Url' => 'https://gateway.test/pim/api/items'])
            ->assertJsonPath('path', 'https://gateway.test/pim/api/items');
    }

    public function test_links_carry_page_number_and_meta_the_total(): void
    {
        $this->getJson('/api/products')
            ->assertJsonPath('links.next', 'http://localhost/api/products?page[number]=3')
            ->assertJsonPath('meta', ['total' => 30]);
    }

    public function test_links_keep_the_rest_of_the_request_query(): void
    {
        $this->getJson('/api/products?filter[status]=active&sort=-id&page[number]=2&page[size]=10&currency_id=2')
            ->assertJsonPath('links.prev', 'http://localhost/api/products?filter[status]=active&sort=-id&page[number]=1&page[size]=10&currency_id=2')
            ->assertJsonPath('links.next', 'http://localhost/api/products?filter[status]=active&sort=-id&page[number]=3&page[size]=10&currency_id=2');
    }

    public function test_nothing_of_a_json_api_request_outlives_it(): void
    {
        $this->getJson('/api/items?page[number]=3', ['X-Gateway-Base-Url' => 'https://gateway.test']);

        // A request that does not pass the middleware paginates as Laravel does.
        $this->getJson('/api/plain-items?page=2')
            ->assertJsonPath('path', 'http://localhost/api/plain-items')
            ->assertJsonPath('page', 2);

        $this->getJson('/api/plain-products')
            ->assertJsonPath('links.next', 'http://localhost/api/plain-products?page=3')
            ->assertJsonPath('meta.current_page', 2);
    }
}
