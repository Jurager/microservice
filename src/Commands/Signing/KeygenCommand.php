<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands\Signing;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Jurager\Microservice\Support\Ecdsa;
use RuntimeException;

use function Laravel\Prompts\confirm;

#[Signature('microservice:keygen {--show : Only print the key pair, do not write to .env}')]
#[Description('Generate an ECDSA (P-256) key pair for signing this service\'s traffic.')]
class KeygenCommand extends Command
{
    public function handle(): void
    {
        try {
            $pair = Ecdsa::generateKeyPair();
        } catch (RuntimeException $e) {
            $this->fail($e->getMessage());
        }

        [$privateKey, $publicKey] = [$pair['private'], $pair['public']];

        if (! $this->option('show') && $this->writeToEnvironmentFile($privateKey)) {
            $this->components->info('SERVICE_PRIVATE_KEY set in .env.');
        } else {
            $this->components->warn('SERVICE_PRIVATE_KEY was not written automatically — set it manually:');
            $this->line($privateKey);
        }

        $this->newLine();
        $this->components->info('Public key — not a secret, hand it to whoever holds the CA key:');
        $this->line($publicKey);
        $this->newLine();
        $this->line('Get it certified:');
        $this->line('  php artisan microservice:certificate:issue '.config('microservice.name', 'app')." $publicKey");
        $this->line("The output goes in this service's own .env as SERVICE_CERTIFICATE.");
        $this->newLine();
        $this->components->warn('Never commit or share the private key. Each service needs its own — do not reuse this pair elsewhere.');
    }

    /** Write (or replace) SERVICE_PRIVATE_KEY in the application's .env file. */
    private function writeToEnvironmentFile(string $privateKey): bool
    {
        $path = $this->laravel->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        $line = "SERVICE_PRIVATE_KEY=$privateKey";

        if (preg_match('/^SERVICE_PRIVATE_KEY=.*$/m', $contents) === 1) {
            if (! confirm('SERVICE_PRIVATE_KEY already set in .env. Overwrite it?', default: false)) {
                return false;
            }

            $contents = preg_replace('/^SERVICE_PRIVATE_KEY=.*$/m', $line, $contents, 1);
        } else {
            $contents = rtrim($contents)."\n".$line."\n";
        }

        return file_put_contents($path, $contents) !== false;
    }
}
