<?php

declare(strict_types=1);

namespace Jurager\Microservice\Http\Controllers;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Jurager\Microservice\Client\ServiceClient;
use Jurager\Microservice\Exceptions\ServiceRequestException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function fclose;
use function is_resource;

class ProxyController extends Controller
{
    /**
     * @param  list<string>  $forwardHeaders  Client headers passed downstream, besides X-Request-Id.
     * @param  list<string>  $stripHeaders  Downstream response headers dropped before the client sees them.
     */
    public function __construct(
        #[Config('microservice.proxy.forward_headers', [])] private readonly array $forwardHeaders = [],
        #[Config('microservice.proxy.strip_headers', [])] private readonly array $stripHeaders = [],
    ) {
    }

    /**
     * @throws Throwable
     */
    public function handle(Request $request, ServiceClient $client): Response
    {
        $service = $request->route()->getAction('_service');

        $pending = $client->service($service)
            ->withMethod($request->method(), $this->resolveProxyPath($request))
            ->withHeaders($this->forwardedHeaders($request))
            ->withQueryParameters($request->query())
            ->withoutThrowing();

        $multipart = null;

        if (! $request->isMethodSafe()) {
            if ($request->files->count() > 0) {
                $pending->withMultipart($multipart = $this->buildMultipart($request));
            } elseif (($content = $request->getContent()) !== '') {
                // Forwarded byte for byte: decoding and re-encoding would turn `{}` into `[]`.
                $pending->withBody($content, (string) ($request->header('Content-Type') ?: 'application/json'));
            }
        }

        try {
            $response = $pending->send();
        } finally {
            foreach ($multipart ?? [] as $part) {
                if (isset($part['contents']) && is_resource($part['contents'])) {
                    @fclose($part['contents']);
                }
            }
        }

        // A failure of the service itself, or of the trust between services, is not the client's to see.
        if (ServiceRequestException::statusFor($response->status()) !== $response->status()) {
            throw new ServiceRequestException($response->status(), service: $service);
        }

        return new Response($response->body(), $response->status(), $this->filterHeaders($response->headers()));
    }

    /**
     * Headers telling the service where the client called, plus the client headers configured to pass on.
     *
     * @return array<string, string>
     */
    protected function forwardedHeaders(Request $request): array
    {
        $headers = [
            'X-Forwarded-Host' => $request->getHttpHost(),
            'X-Forwarded-Proto' => $request->getScheme(),
            'X-Forwarded-Port' => (string) $request->getPort(),
            'X-Gateway-Base-Url' => $request->url(),
        ];

        $prefix = (string) ($request->route()->getAction('_service_prefix') ?? '');

        if ($prefix !== '') {
            $headers['X-Forwarded-Prefix'] = '/'.trim($prefix, '/');
        }

        foreach (['X-Request-Id', ...$this->forwardHeaders] as $name) {
            $value = $request->header($name);

            if (is_string($value) && $value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    protected function buildMultipart(Request $request): array
    {
        $multipart = [];

        foreach ($this->flattenFields($request->post()) as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
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
     * Flatten nested form fields into bracket-notation keys.
     *
     * @param  array<string|int, mixed>  $fields
     * @return array<string, string>
     */
    private function flattenFields(array $fields, string $prefix = ''): array
    {
        $result = [];

        foreach ($fields as $key => $value) {
            $name = $prefix !== '' ? "{$prefix}[$key]" : (string) $key;

            if (is_array($value)) {
                $result += $this->flattenFields($value, $name);
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Flatten nested uploaded files into bracket-notation keys.
     *
     * @param  array<string|int, mixed>  $files
     * @return array<string, UploadedFile>
     */
    private function flattenFiles(array $files, string $prefix = ''): array
    {
        $result = [];

        foreach ($files as $key => $value) {
            $name = $prefix !== '' ? "{$prefix}[$key]" : (string) $key;

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

        // Encoded, so a parameter can never add a segment, a query or a fragment to the path.
        foreach ($request->route()->parameters() as $key => $value) {
            $path = str_replace(['{'.$key.'}', '{'.$key.'?}'], rawurlencode((string) $value), $path);
        }

        // An optional parameter left out leaves its placeholder behind.
        return (string) preg_replace('#/?\{[^}]+\?\}#', '', $path);
    }

    protected function filterHeaders(array $headers): array
    {
        // The body is answered whole, so the framework sets its length again.
        $exclude = array_map('strtolower', ['transfer-encoding', 'connection', 'content-length', ...$this->stripHeaders]);

        $mapped = [];

        foreach ($headers as $name => $values) {
            if (! in_array(strtolower($name), $exclude, true)) {
                $mapped[$name] = implode(', ', (array) $values);
            }
        }

        return $mapped;
    }
}
