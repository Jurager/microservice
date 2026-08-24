<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Feature;

use Jurager\Microservice\Tests\TestCase;

class CertificateIssueCommandTest extends TestCase
{
    public function test_issues_a_certificate_via_the_ca_key_option(): void
    {
        $ca = self::generateKeyPair();
        $service = self::generateKeyPair();

        $this->artisan('microservice:certificate:issue', [
            'service' => 'billing',
            'public-key' => $service['public'],
            '--ca-key' => $ca['private'],
        ])
            ->expectsOutputToContain('Certificate issued for [billing]')
            ->assertSuccessful();
    }

    public function test_issues_a_certificate_via_the_interactive_prompt(): void
    {
        $ca = self::generateKeyPair();
        $service = self::generateKeyPair();

        $this->artisan('microservice:certificate:issue', [
            'service' => 'billing',
            'public-key' => $service['public'],
        ])
            ->expectsQuestion('CA private key', $ca['private'])
            ->expectsOutputToContain('Certificate issued for [billing]')
            ->assertSuccessful();
    }

    public function test_fails_cleanly_when_the_ca_key_prompt_is_left_blank(): void
    {
        $service = self::generateKeyPair();

        // The `required`/`validate` closures on the prompt itself only run
        // in a real terminal — Laravel's `expectsQuestion` test double is a
        // scripted answer, not a re-prompt loop — so this only asserts the
        // command still fails cleanly rather than crashing. The re-prompt
        // behavior itself is a Laravel Prompts guarantee, exercised
        // manually against a real terminal, not here.
        $this->artisan('microservice:certificate:issue', [
            'service' => 'billing',
            'public-key' => $service['public'],
        ])
            ->expectsQuestion('CA private key', '')
            ->assertFailed();
    }

    public function test_fails_for_a_malformed_public_key(): void
    {
        $this->artisan('microservice:certificate:issue', [
            'service' => 'billing',
            'public-key' => 'not-a-real-key',
            '--ca-key' => self::generateKeyPair()['private'],
        ])->assertFailed();
    }

    public function test_fails_for_a_malformed_ca_key_option(): void
    {
        $this->artisan('microservice:certificate:issue', [
            'service' => 'billing',
            'public-key' => self::generateKeyPair()['public'],
            '--ca-key' => 'not-a-real-key',
        ])->assertFailed();
    }

    public function test_prompts_for_missing_service_argument(): void
    {
        // TestCase configures a signing.private_key by default, so the public
        // key is auto-derived and only 'service' and the CA key are prompted for.
        $ca = self::generateKeyPair();

        $this->artisan('microservice:certificate:issue')
            ->expectsQuestion('Which service is this certificate for? (must match its SERVICE_NAME)', 'billing')
            ->expectsQuestion('CA private key', $ca['private'])
            ->expectsOutputToContain('Certificate issued for [billing]')
            ->assertSuccessful();
    }

    public function test_derives_public_key_from_locally_configured_private_key(): void
    {
        $ca = self::generateKeyPair();

        $this->artisan('microservice:certificate:issue', [
            'service' => 'billing',
        ])
            ->expectsQuestion('CA private key', $ca['private'])
            ->expectsOutputToContain("Using this service's own public key")
            ->expectsOutputToContain('Certificate issued for [billing]')
            ->assertSuccessful();
    }

    public function test_prompts_for_public_key_when_no_local_private_key_is_configured(): void
    {
        config(['microservice.signing.private_key' => '']);

        $ca = self::generateKeyPair();
        $service = self::generateKeyPair();

        $this->artisan('microservice:certificate:issue', [
            'service' => 'billing',
        ])
            ->expectsQuestion('What is its public key?', $service['public'])
            ->expectsQuestion('CA private key', $ca['private'])
            ->expectsOutputToContain('Certificate issued for [billing]')
            ->assertSuccessful();
    }

    public function test_issues_certificates_for_several_services_via_for_option(): void
    {
        $ca = self::generateKeyPair();
        $oms = self::generateKeyPair();
        $pim = self::generateKeyPair();

        $this->artisan('microservice:certificate:issue', [
            '--for' => ["oms:{$oms['public']}", "pim:{$pim['public']}"],
            '--ca-key' => $ca['private'],
        ])
            ->expectsOutputToContain('Certificate issued for [oms]')
            ->expectsOutputToContain('Certificate issued for [pim]')
            ->assertSuccessful();
    }

    public function test_for_option_generates_a_fresh_pair_for_a_bare_service_name(): void
    {
        $ca = self::generateKeyPair();

        $this->artisan('microservice:certificate:issue', [
            '--for' => ['oms'],
            '--ca-key' => $ca['private'],
        ])
            ->expectsOutputToContain('Key pair generated for [oms]')
            ->expectsOutputToContain('Certificate issued for [oms]')
            ->assertSuccessful();
    }

    public function test_for_option_fails_on_a_malformed_pair(): void
    {
        $this->artisan('microservice:certificate:issue', [
            '--for' => [':not-a-service-name'],
            '--ca-key' => self::generateKeyPair()['private'],
        ])->assertFailed();
    }

    public function test_for_option_skips_invalid_keys_but_issues_the_rest(): void
    {
        $ca = self::generateKeyPair();
        $pim = self::generateKeyPair();

        $this->artisan('microservice:certificate:issue', [
            '--for' => ['oms:not-a-real-key', "pim:{$pim['public']}"],
            '--ca-key' => $ca['private'],
        ])
            ->expectsOutputToContain('Skipping [oms]')
            ->expectsOutputToContain('Certificate issued for [pim]')
            ->assertSuccessful();
    }

    public function test_issues_certificates_from_a_manifest_file(): void
    {
        $ca = self::generateKeyPair();
        $oms = self::generateKeyPair();
        $pim = self::generateKeyPair();

        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, <<<MANIFEST
            # cluster services
            oms {$oms['public']}

            pim {$pim['public']}
            MANIFEST);

        try {
            $this->artisan('microservice:certificate:issue', [
                '--manifest' => $path,
                '--ca-key' => $ca['private'],
            ])
                ->expectsOutputToContain('Certificate issued for [oms]')
                ->expectsOutputToContain('Certificate issued for [pim]')
                ->assertSuccessful();
        } finally {
            unlink($path);
        }
    }

    public function test_manifest_generates_a_fresh_pair_for_a_bare_service_line(): void
    {
        $ca = self::generateKeyPair();
        $pim = self::generateKeyPair();

        $path = tempnam(sys_get_temp_dir(), 'manifest');
        file_put_contents($path, <<<MANIFEST
            oms
            pim {$pim['public']}
            MANIFEST);

        try {
            $this->artisan('microservice:certificate:issue', [
                '--manifest' => $path,
                '--ca-key' => $ca['private'],
            ])
                ->expectsOutputToContain('Key pair generated for [oms]')
                ->expectsOutputToContain('Certificate issued for [oms]')
                ->expectsOutputToContain('Certificate issued for [pim]')
                ->assertSuccessful();
        } finally {
            unlink($path);
        }
    }

    public function test_fails_for_a_missing_manifest_file(): void
    {
        $this->artisan('microservice:certificate:issue', [
            '--manifest' => '/nonexistent/manifest.txt',
            '--ca-key' => self::generateKeyPair()['private'],
        ])->assertFailed();
    }
}
