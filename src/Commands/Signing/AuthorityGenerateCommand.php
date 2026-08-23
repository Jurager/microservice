<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands\Signing;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Jurager\Microservice\Support\Ecdsa;
use RuntimeException;

use function Laravel\Prompts\callout;

#[Signature('microservice:authority:generate')]
#[Description('Generate the cluster key pair used to issue service certificates.')]
class AuthorityGenerateCommand extends Command
{
    public function handle(): void
    {
        try {
            $pair = Ecdsa::generateKeyPair();
        } catch (RuntimeException $e) {
            $this->fail($e->getMessage());
        }

        callout(
            label: 'Cluster CA generated',
            content: [
                'Never share the private key — keep it offline.',
                'Use it only with `microservice:certificate:issue` to sign certificates.',
            ],
            type: 'warning',
        );

        $this->components->info('CA private key — keep this off the running infrastructure entirely:');
        $this->line($pair['private']);
        $this->newLine();

        $this->components->info('CA public key — set as SERVICE_CA_PUBLIC_KEY on every service, identically:');
        $this->line($pair['public']);
    }
}
