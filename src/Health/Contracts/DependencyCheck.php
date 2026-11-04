<?php

declare(strict_types=1);

namespace Jurager\Microservice\Health\Contracts;

use Jurager\Microservice\Health\DependencyStatus;

/**
 * Check the status of a service dependency.
 */
interface DependencyCheck
{
    /**
     * Key the dependency is reported under, e.g. "cache".
     */
    public function name(): string;

    /**
     * Whether the service cannot serve traffic while the dependency is down.
     */
    public function critical(): bool;

    /**
     * Whether the check applies to this service at all.
     */
    public function enabled(): bool;

    public function check(): DependencyStatus;
}
