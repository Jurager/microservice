<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands\Signing;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use Jurager\Microservice\Support\Certificate;
use Jurager\Microservice\Support\Ecdsa;
use RuntimeException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('microservice:certificate:issue
             {service?     : Name the certificate is issued for (must match its SERVICE_NAME). Omit when using --for or --manifest}
             {public-key?  : The service\'s own public key. Defaults to this service\'s own SERVICE_PRIVATE_KEY, when run on the service itself}
             {--for=*      : Issue for several services in one pass: --for=oms:base64pubkey (existing key) or --for=oms (generate a fresh pair too)}
             {--manifest=  : Path to a file listing "service [public_key]" pairs, one per line — a bare service name generates a fresh pair}
             {--ca-key=    : Base64 CA private key. Prompted for (hidden) when omitted — never pass it on a shared machine\'s shell history}')]
#[Description('Issue one or more certificates binding a service name to its public key, signed by the cluster CA — generating a key pair for any target that does not already have one.')]
class CertificateIssueCommand extends Command
{
    public function handle(): void
    {
        $targets = $this->resolveTargets();
        $generated = $this->generateMissingKeys($targets);

        foreach ($targets as $service => $publicKey) {
            if (! $this->isValidPublicKey($publicKey)) {
                $this->components->error("Skipping [$service]: not a valid base64-encoded ECDSA (P-256) public key.");
                unset($targets[$service], $generated[$service]);
            }
        }

        if ($targets === []) {
            $this->fail('No valid targets to certify.');
        }

        $caPrivateKeyEncoded = (string) ($this->option('ca-key') ?: password(
            label: 'CA private key',
            required: 'The CA private key is required — there is nothing to sign the certificate with otherwise.',
            validate: fn (string $value) => $this->isValidPrivateKey($value)
                ? null
                : 'That is not a valid base64-encoded ECDSA (P-256) private key.',
        ));

        try {
            $ca = Ecdsa::loadPrivateKey($caPrivateKeyEncoded);
        } catch (RuntimeException $e) {
            $this->fail("Could not load the CA private key: {$e->getMessage()}");
        }

        foreach ($targets as $service => $publicKey) {
            try {
                $certificate = Certificate::issue($service, $publicKey, $ca)->encode();
            } catch (RuntimeException|JsonException $e) {
                $this->fail("Could not issue a certificate for [$service]: {$e->getMessage()}");
            }

            if (isset($generated[$service])) {
                $this->components->info("Key pair generated for [$service]. Set on that service as SERVICE_PRIVATE_KEY:");
                $this->line($generated[$service]);
            }

            $this->components->info("Certificate issued for [$service]. Set on that service as SERVICE_CERTIFICATE:");
            $this->line($certificate);
            $this->newLine();
        }

        if ($generated !== []) {
            $this->components->warn('Private keys above are secrets — send each one to its own service over a secure channel and never commit them.');
        }
    }

    /**
     * Generate a key pair for any target whose public key is still unresolved
     * (a bare service name in --for or a single-column manifest line), filling
     * $targets in place and returning the generated private keys by service.
     *
     * @param  array<string, ?string>  $targets
     * @return array<string, string>
     */
    private function generateMissingKeys(array &$targets): array
    {
        $generated = [];

        foreach ($targets as $service => $publicKey) {
            if ($publicKey !== null) {
                continue;
            }

            try {
                $pair = Ecdsa::generateKeyPair();
            } catch (RuntimeException $e) {
                $this->components->error("Skipping [$service]: could not generate a key pair: {$e->getMessage()}");
                unset($targets[$service]);

                continue;
            }

            $targets[$service] = $pair['public'];
            $generated[$service] = $pair['private'];
        }

        return $generated;
    }

    /**
     * Resolve every service to certify this run, in order of precedence: --for, --manifest,
     * then the single service/public-key arguments (prompting or deriving as needed).
     *
     * A null value means "generate a fresh key pair for this service" — see generateMissingKeys().
     *
     * @return array<string, ?string>
     */
    private function resolveTargets(): array
    {
        $for = array_filter((array) $this->option('for'));

        if ($for !== []) {
            return $this->parseForOptions($for);
        }

        $manifest = $this->option('manifest');

        if (is_string($manifest) && $manifest !== '') {
            return $this->parseManifestFile($manifest);
        }

        return $this->resolveSingleTarget();
    }

    /** @return array<string, ?string> */
    private function parseForOptions(array $pairs): array
    {
        $targets = [];

        foreach ($pairs as $pair) {
            $pair = (string) $pair;

            if (! str_contains($pair, ':')) {
                $service = trim($pair);

                if ($service === '') {
                    $this->fail("Malformed --for value (expected service or service:public_key): $pair");
                }

                $targets[$service] = null;

                continue;
            }

            [$service, $publicKey] = explode(':', $pair, 2);
            $service = trim($service);
            $publicKey = trim($publicKey);

            if ($service === '' || $publicKey === '') {
                $this->fail("Malformed --for value (expected service or service:public_key): $pair");
            }

            $targets[$service] = $publicKey;
        }

        return $targets;
    }

    /** @return array<string, ?string> */
    private function parseManifestFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->fail("Cannot read manifest file: $path");
        }

        $targets = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNumber => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);

            if (count($parts) === 1) {
                $targets[$parts[0]] = null;
            } else {
                [$service, $publicKey] = $parts;
                $targets[$service] = $publicKey;
            }
        }

        if ($targets === []) {
            $this->fail("Manifest file has no service entries: $path");
        }

        return $targets;
    }

    /** @return array<string, ?string> */
    private function resolveSingleTarget(): array
    {
        $service = (string) ($this->argument('service') ?: text(
            label: 'Which service is this certificate for? (must match its SERVICE_NAME)',
            default: (string) config('microservice.name', ''),
            required: true,
        ));

        return [$service => $this->resolvePublicKey()];
    }

    /** Use the argument if given; otherwise derive it from this service's own configured private key, or prompt. */
    private function resolvePublicKey(): string
    {
        if ($this->argument('public-key') !== null) {
            return (string) $this->argument('public-key');
        }

        $privateKey = (string) config('microservice.signing.private_key', '');

        if ($privateKey !== '') {
            try {
                $publicKey = Ecdsa::publicKeyFor(Ecdsa::loadPrivateKey($privateKey));

                $this->components->info('Using this service\'s own public key, derived from its configured SERVICE_PRIVATE_KEY.');

                return $publicKey;
            } catch (RuntimeException) {
                // Configured key is unusable — fall through to prompting instead.
            }
        }

        return text(
            label: 'What is its public key?',
            placeholder: 'from `microservice:keygen`',
            required: true,
            validate: fn (string $value) => $this->isValidPublicKey($value)
                ? null
                : 'That is not a valid base64-encoded ECDSA (P-256) public key.',
        );
    }

    private function isValidPublicKey(string $encoded): bool
    {
        try {
            Ecdsa::loadPublicKey($encoded);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function isValidPrivateKey(string $encoded): bool
    {
        try {
            Ecdsa::loadPrivateKey($encoded);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
