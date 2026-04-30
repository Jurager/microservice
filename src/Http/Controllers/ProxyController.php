<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Client\ServiceClient;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProxyController extends Controller
{
    public function handle(Request $request, ServiceClient $client): Response
    {
        $service = $request->route()->getAction('_service');
        $path = $this->resolveProxyPath($request);

        $pending = $client->service($service)->withMethod($request->method(), $path);

        if (! $request->isMethodSafe()) {
            if ($request->files->count() > 0) {
                $pending->withMultipart($this->buildMultipart($request));
            } else {
                $content = $request->getContent();

                if ($content !== '') {
                    try {
                        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                        $body = is_array($decoded) ? $decoded : null;
                    } catch (\JsonException) {
                        $body = null;
                    }

                    if ($body !== null) {
                        $pending->withBody($body);
                    }
                }
            }
        }

        $prefix = $request->route()->getAction('_service_prefix') ?? '';

        $pending->withHeaders([
            'X-Forwarded-Host' => $request->getHttpHost(),
            'X-Forwarded-Proto' => $request->getScheme(),
            'X-Forwarded-Port' => (string) $request->getPort(),
            'X-Forwarded-Prefix' => $prefix !== '' ? '/'.trim($prefix, '/') : '',
        ]);

        if ($query = $request->query()) {
            $pending->with($query);
        }

        $response = $pending->send();
        $headers = $this->filterHeaders($response->headers());
        $status = $response->status();
        $stream = $response->stream();

        return new StreamedResponse(
            function () use ($stream): void {
                while (! $stream->eof()) {
                    echo $stream->read(8192);
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            },
            $status,
            $headers,
        );
    }

    protected function buildMultipart(Request $request): array
    {
        $multipart = [];

        foreach ($this->flattenFields($request->post()) as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => (string) $value];
        }

        foreach ($this->flattenFiles($request->allFiles()) as $name => $file) {
            $multipart[] = [
                'name' => $name,
                'contents' => fopen($file->getRealPath(), 'r'),
                'filename' => $file->getClientOriginalName(),
            ];
        }

        return $multipart;
    }

    /**
     * Recursively flattens nested scalar fields into bracket-notation keys.
     * E.g. ['files' => [['type' => 'products']]] → ['files[0][type]' => 'products']
     *
     * @param  array<string|int, mixed>  $fields
     * @return array<string, string>
     */
    private function flattenFields(array $fields, string $prefix = ''): array
    {
        $result = [];

        foreach ($fields as $key => $value) {
            $name = $prefix !== '' ? "{$prefix}[{$key}]" : (string) $key;

            if (is_array($value)) {
                $result += $this->flattenFields($value, $name);
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Recursively flattens nested file entries into bracket-notation keys.
     * E.g. ['files' => [['file' => UploadedFile]]] → ['files[0][file]' => UploadedFile]
     *
     * @param  array<string|int, mixed>  $files
     * @return array<string, UploadedFile>
     */
    private function flattenFiles(array $files, string $prefix = ''): array
    {
        $result = [];

        foreach ($files as $key => $value) {
            $name = $prefix !== '' ? "{$prefix}[{$key}]" : (string) $key;

            if (is_array($value)) {
                $result += $this->flattenFiles($value, $name);
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    protected function resolveProxyPath(Request $request): string
    {
        $serviceUri = $request->route()->getAction('_service_uri');

        if ($serviceUri === null) {
            return $request->getPathInfo();
        }

        $path = $serviceUri;

        foreach ($request->route()->parameters() as $key => $value) {
            // Use regex to match exact parameter tokens (including optional {param?})
            // and avoid partial matches inside longer parameter names.
            $path = preg_replace('/\{'.preg_quote($key, '/').'(?:\?)?\}/', (string) $value, $path);
        }

        return $path;
    }

    protected function filterHeaders(array $headers): array
    {
        $strip = config('microservice.proxy.strip_headers', []);

        $exclude = array_map('strtolower', array_merge(['transfer-encoding', 'connection'], $strip));

        $mapped = [];

        foreach ($headers as $name => $values) {
            if (! in_array(strtolower($name), $exclude, true)) {
                $mapped[$name] = implode(', ', (array) $values);
            }
        }

        return $mapped;
    }
}
