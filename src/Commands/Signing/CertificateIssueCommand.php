<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands\Signing;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use JsonException;
use Jurager\Microservice\Support\Certificate;
use Jurager\Microservice\Support\Ecdsa;
use RuntimeException;

use function Laravel\Prompts\password;

#[Signature('microservice:certificate:issue
             {service    : Name the certificate is issued for (must match its SERVICE_NAME)}
             {public-key : The service\'s own public key, from `microservice:keygen`}
             {--ca-key=  : Base64 CA private key. Prompted for (hidden) when omitted — never pass it on a shared machine\'s shell history}')]
#[Description('Issue a certificate binding a service name to its public key, signed by the cluster CA.')]
class CertificateIssueCommand extends Command implements PromptsForMissingInput
{
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'service' => 'Which service is this certificate for? (must match its SERVICE_NAME)',
            'public-key' => ['What is its public key?', 'from `microservice:keygen`'],
        ];
    }

    public function handle(): void
    {
        $service = (string) $this->argument('service');
        $publicKey = (string) $this->argument('public-key');

        if (! $this->isValidPublicKey($publicKey)) {
            $this->fail('That is not a valid base64-encoded ECDSA (P-256) public key.');
        }

        $caPrivateKeyEncoded = (string) ($this->option('ca-key') ?: password(
            label: 'CA private key',
            required: 'The CA private key is required — there is nothing to sign the certificate with otherwise.',
            validate: fn (string $value) => $this->isValidPrivateKey($value)
                ? null
                : 'That is not a valid base64-encoded ECDSA (P-256) private key.',
        ));

        try {
            $certificate = Certificate::issue($service, $publicKey, Ecdsa::loadPrivateKey($caPrivateKeyEncoded))->encode();
        } catch (RuntimeException|JsonException $e) {
            $this->fail("Could not issue the certificate: {$e->getMessage()}");
        }

        $this->components->info("Certificate issued for [$service]. Set on that service as SERVICE_CERTIFICATE:");
        $this->line($certificate);
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
