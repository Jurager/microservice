<?php

declare(strict_types=1);

namespace Jurager\Microservice\Bus;

use Illuminate\Container\Attributes\Config;
use Jurager\Microservice\Bus\Contracts\MessageHandler;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Discover configured and application message handlers.
 */
class HandlerDiscovery
{
    /** @var list<class-string<MessageHandler>>|null What the last scan found — the scan reflects over every file. */
    private ?array $discovered = null;

    /**
     * @param  list<class-string>  $handlers  Handlers always used.
     * @param  bool|list<string>  $discover  Scan the app directory (true), the given directories, or nothing (false).
     */
    public function __construct(
        #[Config('microservice.bus.handlers', [])] private readonly array $handlers = [],
        #[Config('microservice.bus.discover', true)] private readonly bool|array $discover = true,
    ) {
    }

    /**
     * @return list<class-string<MessageHandler>>
     */
    public function discover(): array
    {
        return $this->discovered ??= $this->find();
    }

    /**
     * The handlers of each event type, in the order given; the last one is the one consuming it.
     *
     * @param  list<class-string<MessageHandler>>  $classes
     * @return array<string, non-empty-list<class-string<MessageHandler>>>
     */
    public static function byType(array $classes): array
    {
        $types = [];

        foreach ($classes as $class) {
            foreach ((array) $class::type() as $type) {
                $types[$type][] = $class;
            }
        }

        return $types;
    }

    /**
     * Forget what was found, so the next discover() scans again.
     */
    public function forget(): void
    {
        $this->discovered = null;
    }

    /**
     * @return list<class-string<MessageHandler>>
     */
    private function find(): array
    {
        $handlers = array_filter(
            $this->handlers,
            fn (mixed $class): bool => is_string($class) && $this->isHandler($class),
        );

        array_push($handlers, ...$this->scan());

        $handlers = array_values(array_unique($handlers));

        sort($handlers);

        return $handlers;
    }

    /**
     * @return list<class-string<MessageHandler>>
     */
    protected function scan(): array
    {
        $handlers = [];

        foreach ($this->paths() as $path) {
            foreach (new Finder()->files()->name('*.php')->in($path) as $file) {
                // Only a file naming the contract can hold a handler — any other is never loaded.
                if (! str_contains($file->getContents(), 'MessageHandler')) {
                    continue;
                }

                $class = $this->classFromFile($file);

                if ($class !== null && $this->isHandler($class)) {
                    $handlers[] = $class;
                }
            }
        }

        return $handlers;
    }

    /** @return list<string> The existing directories to scan. */
    protected function paths(): array
    {
        $paths = match (true) {
            $this->discover === true => [app_path()],
            is_array($this->discover) => $this->discover,
            default => [],
        };

        return array_values(array_filter($paths, is_dir(...)));
    }

    private function isHandler(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable) {
            return false;
        }

        return ! $reflection->isAbstract() && $reflection->implementsInterface(MessageHandler::class);
    }

    protected function classFromFile(SplFileInfo $file): ?string
    {
        $basePath = base_path();
        $appPath = app_path();

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
