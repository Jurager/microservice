<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Client\ServiceClient;

class ProxyController extends Controller
{
    public function handle(Request $request, ServiceClient $client): Response
    {
        $service = $request->route()->getAction('_service');
        $path = $this->resolveProxyPath($request);
        $body = null;

        if (!$request->isMethodSafe()) {
            $content = $request->getContent();

            if ($content !== '') {
                $decoded = json_decode($content, true);
                $body = is_array($decoded) ? $decoded : null;
            }
        }

        $pending = $client->service($service)->withMethod($request->method(), $path, $body);

        $prefix = $request->route()->getAction('_service_prefix') ?? '';

        $pending->withHeaders([
            'X-Forwarded-Host' => $request->getHttpHost(),
            'X-Forwarded-Proto' => $request->getScheme(),
            'X-Forwarded-Port' => (string) $request->getPort(),
            'X-Forwarded-Prefix' => $prefix !== '' ? '/' . trim($prefix, '/') : '',
        ]);

        if ($query = $request->query()) {
            $pending->withQuery($query);
        }

        $response = $pending->send();

        return response($response->body(), $response->status())
            ->withHeaders($this->filterHeaders($response->headers()));
    }

    protected function resolveProxyPath(Request $request): string
    {
        $serviceUri = $request->route()->getAction('_service_uri');

        if ($serviceUri === null) {
            return $request->getPathInfo();
        }

        $path = $serviceUri;

        foreach ($request->route()->parameters() as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }

        return $path;
    }

    protected function filterHeaders(array $headers): array
    {
        $strip = config('microservice.proxy.strip_headers', []);

        $exclude = array_map('strtolower', array_merge(['transfer-encoding', 'connection'], $strip));

        $mapped = [];

        foreach ($headers as $name => $values) {
            if (!in_array(strtolower($name), $exclude, true)) {
                $mapped[$name] = implode(', ', (array) $values);
            }
        }

        return $mapped;
    }
}
