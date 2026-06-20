<?php

namespace App\Actions\Service;

use App\Models\ServiceApplication;
use Lorisleiva\Actions\Concerns\AsAction;

class ScaleServiceApplication
{
    use AsAction;

    /**
     * Scale a service application to the given replica count.
     *
     * Re-parses and re-saves the compose file so the deploy.replicas key is up to
     * date, then issues `docker compose up -d --no-recreate --scale` to apply the
     * change without stopping healthy containers.
     */
    public function handle(ServiceApplication $serviceApplication, int $replicas): void
    {
        $replicas = max(
            max(1, (int) ($serviceApplication->autoscale_min_replicas ?? 1)),
            min(max(1, (int) ($serviceApplication->autoscale_max_replicas ?? 50)), $replicas)
        );

        $service = $serviceApplication->service;
        $workdir = $service->workdir();
        $server = $service->server;

        $serviceApplication->replicas = $replicas;
        $serviceApplication->autoscale_last_scaled_at = now();
        $serviceApplication->save();

        // Re-parse so the compose file reflects the updated replica count
        // (the parser will inject deploy.replicas when replicas > 1 and skip container_name)
        $service->parse();
        $service->saveComposeConfigs();

        $name = $serviceApplication->name;
        $uuid = $service->uuid;

        instant_remote_process([
            "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$uuid} up -d --no-recreate --scale {$name}={$replicas}",
        ], $server);
    }
}
