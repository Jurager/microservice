<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Jurager\Microservice\Bus\Contracts\MessageHandler;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Discovers MessageHandler implementations by scanning the application path.
 *
 * Runs once at the start of microservice:listen / microservice:events. Long-
 * running consumer processes pay the cost only at boot, so caching isn't
 * needed — the cost is one filesystem walk per worker lifetime.
 *
 * Override the binding in tests or in apps that need a different scan path.
 */
class HandlerDiscovery
{
    /**
     * @return list<class-string<MessageHandler>>
     */
    public function discover(): array
    {
        $path = $this->path();

        if (! is_dir($path)) {
            return [];
        }

        $handlers = [];

        foreach (new Finder()->files()->name('*.php')->in($path) as $file) {
            $class = $this->classFromFile($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                continue;
            }

            if ($reflection->isAbstract() || ! $reflection->implementsInterface(MessageHandler::class)) {
                continue;
            }

            $handlers[] = $class;
        }

        sort($handlers);

        return $handlers;
    }

    protected function path(): string
    {
        return app_path();
    }

    protected function classFromFile(SplFileInfo $file): ?string
    {
        $basePath = base_path();
        $appPath  = app_path();

        $class = trim(str_replace($basePath, '', $file->getRealPath()), DIRECTORY_SEPARATOR);
        $class = str_replace(['.php', DIRECTORY_SEPARATOR], ['', '\\'], $class);

        // Replace the on-disk app directory segment (default: "app") with the
        // PHP namespace it's psr-4 mapped to (default: "App").
        return str_replace(
            ucfirst(basename($appPath)).'\\',
            app()->getNamespace(),
            ucfirst($class),
        );
    }
}
