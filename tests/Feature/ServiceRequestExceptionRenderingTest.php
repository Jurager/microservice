<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Exceptions\ServiceRequestException;
use Jurager\Microservice\Tests\TestCase;

class ServiceRequestExceptionRenderingTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/pim/v1/categories/1', function () {
            throw new ServiceRequestException(404, errors: [
                ['status' => '404', 'title' => 'Not Found', 'detail' => 'No query results for model [Category] 1.'],
            ]);
        });
    }

    public function test_upstream_status_is_preserved_for_json_clients(): void
    {
        $response = $this->getJson('/pim/v1/categories/1');

        $response->assertStatus(404);
        $response->assertJsonPath('errors.0.detail', 'No query results for model [Category] 1.');
    }

    /**
     * The JSON:API renderer only kicks in for JSON clients, so a browser hitting a
     * gateway route used to fall through to the generic handler and get a 500.
     */
    public function test_upstream_status_is_preserved_for_non_json_clients(): void
    {
        $response = $this->get('/pim/v1/categories/1', ['Accept' => 'text/html']);

        $response->assertStatus(404);
    }
}
