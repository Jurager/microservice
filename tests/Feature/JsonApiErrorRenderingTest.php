<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Jurager\Microservice\Tests\TestCase;
use RuntimeException;

class JsonApiErrorRenderingTest extends TestCase
{
    /**
     * The routes of the service live in the api middleware group under a custom prefix,
     * the same way a consuming app's withRouting(api: ..., apiPrefix: 'v1') puts them.
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.debug', false);
        $app['router']->middlewareGroup('api', []);
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('api')->prefix('v1')->group(function ($router): void {
            $router->post('/orders', function () {
                throw ValidationException::withMessages(['name' => ['The name field is required.']]);
            });

            $router->get('/failure', function () {
                throw new RuntimeException('SQLSTATE[08006] connection to server at "db.internal" failed');
            });

            $router->get('/paid', function () {
                throw new HttpResponseException(response('Order is already paid.', 409));
            });
        });

        $router->post('/web/orders', function () {
            throw ValidationException::withMessages(['name' => ['The name field is required.']]);
        });
    }

    public function test_api_routes_answer_json_api_errors_whatever_the_client_accepts(): void
    {
        $response = $this->post('/v1/orders', [], ['Accept' => 'text/html']);

        $response->assertStatus(422);
        $response->assertHeader('Content-Type', 'application/vnd.api+json');
        $response->assertJsonPath('errors.0.source.pointer', '/name');
    }

    /**
     * Laravel treats an AJAX request accepting anything as a JSON client, so the package does the same.
     */
    public function test_ajax_clients_get_json_api_errors_outside_the_api_group(): void
    {
        $response = $this->post('/web/orders', [], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => '*/*']);

        $response->assertStatus(422);
        $response->assertHeader('Content-Type', 'application/vnd.api+json');
    }

    public function test_routes_outside_the_api_group_keep_the_default_rendering_for_non_json_clients(): void
    {
        $this->post('/web/orders', [], ['Accept' => 'text/html'])->assertStatus(302);
    }

    public function test_unexpected_exceptions_do_not_reveal_their_message(): void
    {
        $response = $this->getJson('/v1/failure');

        $response->assertStatus(500);
        $response->assertJsonPath('errors.0.detail', 'An unexpected error occurred.');
        $this->assertStringNotContainsString('db.internal', $response->getContent());
    }

    public function test_ready_responses_are_returned_as_is(): void
    {
        $this->getJson('/v1/paid')->assertStatus(409)->assertSee('Order is already paid.');
    }
}
