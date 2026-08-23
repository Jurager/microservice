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

    public function test_prompts_for_missing_arguments(): void
    {
        $ca = self::generateKeyPair();
        $service = self::generateKeyPair();

        $this->artisan('microservice:certificate:issue')
            ->expectsQuestion('Which service is this certificate for? (must match its SERVICE_NAME)', 'billing')
            ->expectsQuestion('What is its public key?', $service['public'])
            ->expectsQuestion('CA private key', $ca['private'])
            ->expectsOutputToContain('Certificate issued for [billing]')
            ->assertSuccessful();
    }
}
