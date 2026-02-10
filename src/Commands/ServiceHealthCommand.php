<?php

declare(strict_types=1);

namespace Jurager\Microservice\Commands;

use Illuminate\Console\Command;
use Jurager\Microservice\Registry\HealthRegistry;

class ServiceHealthCommand extends Command
{
    protected $signature = 'service:health';

    protected $description = 'Display health status of all configured service instances';

    public function handle(HealthRegistry $registry): int
    {
        $health = $registry->getAllHealth();

        if (empty($health)) {
            $this->components->warn('No services configured.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($health as $service => $instances) {
            foreach ($instances as $instance) {
                $rows[] = [
                    $service,
                    $instance['url'],
                    $instance['failures'],
                    $instance['last_failure']
                        ? date('Y-m-d H:i:s', $instance['last_failure'])
                        : '-',
                    $instance['healthy'] ? '<fg=green>healthy</>' : '<fg=red>unhealthy</>',
                ];
            }
        }

        $this->table(
            ['Service', 'URL', 'Failures', 'Last Failure', 'Status'],
            $rows,
        );

        return self::SUCCESS;
    }
}
