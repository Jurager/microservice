<?php

declare(strict_types=1);

namespace Jurager\Microservice\Tests\Unit;

use Jurager\Microservice\Bus\HandlerDiscovery;
use Jurager\Microservice\Tests\TestCase;

class HandlerDiscoveryTest extends TestCase
{
    public function test_the_application_is_scanned_once_per_process(): void
    {
        $discovery = new class extends HandlerDiscovery
        {
            public int $scans = 0;

            protected function scan(): array
            {
                $this->scans++;

                return [];
            }
        };

        $discovery->discover();
        $discovery->discover();
        $this->assertSame(1, $discovery->scans);

        $discovery->forget();
        $discovery->discover();
        $this->assertSame(2, $discovery->scans);
    }

    public function test_discover_names_the_directories_scanned(): void
    {
        $paths = static fn (bool|array $discover): array => (fn (): array => $this->paths())->call(new HandlerDiscovery(discover: $discover));

        $this->assertSame([app_path()], $paths(true));
        $this->assertSame([], $paths(false));
        $this->assertSame([sys_get_temp_dir()], $paths([sys_get_temp_dir(), '/no/such/directory']));
    }

    public function test_files_that_do_not_name_the_contract_are_never_loaded(): void
    {
        $directory = sys_get_temp_dir().'/handlers-'.uniqid();
        mkdir($directory);
        file_put_contents("$directory/helpers.php", '<?php throw new RuntimeException("This file must never be loaded.");');

        try {
            $this->assertSame([], (new HandlerDiscovery(discover: [$directory]))->discover());
        } finally {
            unlink("$directory/helpers.php");
            rmdir($directory);
        }
    }
}
