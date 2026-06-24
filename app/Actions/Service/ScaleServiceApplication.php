<?php

namespace App\Actions\Service;

use App\Models\ServiceApplication;
use Lorisleiva\Actions\Concerns\AsAction;

class ScaleServiceApplication
{
    use AsAction;

    /**
     * Absolute upper bound for replicas, matching the UI input and the Livewire
     * validation rule (1..50). Autoscaling enforces its own configured min/max
     * separately in CheckServiceAutoscalingJob.
     */
    public const MAX_REPLICAS = 50;

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
        // Only clamp to the absolute supported range. The manual "Apply Now" path must
        // not be limited by autoscale_max_replicas (default 5) — that bound is for
        // autoscaling only, which already keeps its decisions within min/max in
        // CheckServiceAutoscalingJob before calling this action.
        $replicas = self::clampReplicas($replicas);

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

    /**
     * Clamp a requested replica count to the absolute supported range [1, MAX_REPLICAS].
     */
    public static function clampReplicas(int $replicas): int
    {
        return max(1, min(self::MAX_REPLICAS, $replicas));
    }
}
