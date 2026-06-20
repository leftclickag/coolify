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
     * date, then issues `docker compose up -d --force-recreate --scale` targeting
     * only the named service.
     *
     * When replicas > 1, the parser automatically converts any host port bindings
     * into `expose` entries (host ports cannot be shared across replicas); the proxy
     * load-balances across them, or they stay internal-only if no FQDN is set.
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

        // --force-recreate targets only this service (name appended at end).
        // This is intentional: when scaling from 1 → N the original container had a
        // static container_name that is now removed from the compose file; without
        // force-recreate Docker Compose would leave that oddly-named container running
        // alongside correctly-numbered new ones.  Targeting only $name means the other
        // services in the stack are never touched.
        $commands = [
            "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml --project-name {$uuid} up -d --force-recreate --scale {$name}={$replicas} {$name}",
        ];

        // If connect_to_docker_network is enabled, each new replica must also be
        // connected to the server's destination network so it can reach other Coolify
        // resources.  StartService does this for replica-1 via container_name; we
        // replicate it for all replicas here using Docker Compose labels to find them.
        if (data_get($service, 'connect_to_docker_network')) {
            $destinationNetwork = escapeshellarg($service->destination->network);
            for ($i = 1; $i <= $replicas; $i++) {
                $containerName = "{$uuid}-{$name}-{$i}";
                $alias = escapeshellarg("{$name}-{$uuid}");
                $commands[] = "docker network connect --alias {$alias} {$destinationNetwork} {$containerName} 2>/dev/null || true";
            }
        }

        instant_remote_process($commands, $server);
    }
}
