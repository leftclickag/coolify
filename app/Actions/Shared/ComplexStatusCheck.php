<?php

namespace App\Actions\Shared;

use App\Models\Application;
use App\Models\ApplicationDockerService;
use App\Services\ContainerStatusAggregator;
use App\Traits\CalculatesExcludedStatus;
use Lorisleiva\Actions\Concerns\AsAction;

class ComplexStatusCheck
{
    use AsAction;
    use CalculatesExcludedStatus;

    public function handle(Application $application)
    {
        $servers = $application->additional_servers;
        $servers->push($application->destination->server);
        foreach ($servers as $server) {
            $is_main_server = $application->destination->server->id === $server->id;
            if (! $server->isFunctional()) {
                if ($is_main_server) {
                    $application->update(['status' => 'exited']);
                    $this->markDockerServicesExited($application);

                    continue;
                } else {
                    $application->additional_servers()->updateExistingPivot($server->id, ['status' => 'exited']);

                    continue;
                }
            }
            $containers = instant_remote_process(["docker container inspect $(docker container ls -q --filter 'label=coolify.applicationId={$application->id}' --filter 'label=coolify.pullRequestId=0') --format '{{json .}}'"], $server, false);
            $containers = format_docker_command_output_to_json($containers);

            if ($containers->count() > 0) {
                $statusToSet = $this->aggregateContainerStatuses($application, $containers);

                if ($is_main_server) {
                    $statusFromDb = $application->status;
                    if ($statusFromDb !== $statusToSet) {
                        $application->update(['status' => $statusToSet]);
                    }
                    $this->updateDockerServiceStatuses($application, $containers);
                } else {
                    $additional_server = $application->additional_servers()->wherePivot('server_id', $server->id);
                    $statusFromDb = $additional_server->first()->pivot->status;
                    if ($statusFromDb !== $statusToSet) {
                        $additional_server->updateExistingPivot($server->id, ['status' => $statusToSet]);
                    }
                }
            } else {
                if ($is_main_server) {
                    $application->update(['status' => 'exited']);
                    $this->markDockerServicesExited($application);

                    continue;
                } else {
                    $application->additional_servers()->updateExistingPivot($server->id, ['status' => 'exited']);

                    continue;
                }
            }
        }
    }

    private function updateDockerServiceStatuses(Application $application, $containers): void
    {
        if ($application->build_pack !== 'dockercompose') {
            return;
        }

        $dockerServices = ApplicationDockerService::where('application_id', $application->id)->get()->keyBy('name');
        if ($dockerServices->isEmpty()) {
            return;
        }

        // Merge replicas that share a compose service name so a failed replica is not
        // masked by a healthy one — consistent with how container statuses are aggregated
        // elsewhere (GetContainersStatus / PushServerUpdateJob).
        $aggregator = new ContainerStatusAggregator;
        $statusByService = [];
        foreach ($containers as $container) {
            $labels = data_get($container, 'Config.Labels', []);
            $serviceName = data_get($labels, 'com.docker.compose.service');
            if (! $serviceName) {
                continue;
            }
            $state = data_get($container, 'State.Status', 'exited');
            $health = data_get($container, 'State.Health.Status');
            $containerStatus = $health ? "{$state}:{$health}" : $state;

            $statusByService[$serviceName] = $aggregator->mergeStatusStrings(
                $statusByService[$serviceName] ?? null,
                $containerStatus
            );
        }

        foreach ($dockerServices as $name => $dockerService) {
            $newStatus = $statusByService[$name] ?? 'exited';
            if ($dockerService->status !== $newStatus) {
                $dockerService->update(['status' => $newStatus]);
            }
        }
    }

    private function markDockerServicesExited(Application $application): void
    {
        if ($application->build_pack !== 'dockercompose') {
            return;
        }

        ApplicationDockerService::where('application_id', $application->id)
            ->where('status', '!=', 'exited')
            ->update(['status' => 'exited']);
    }

    private function aggregateContainerStatuses($application, $containers)
    {
        $dockerComposeRaw = data_get($application, 'docker_compose_raw');
        $excludedContainers = $this->getExcludedContainersFromDockerCompose($dockerComposeRaw);

        // Filter non-excluded containers
        $relevantContainers = collect($containers)->filter(function ($container) use ($excludedContainers) {
            $labels = data_get($container, 'Config.Labels', []);
            $serviceName = data_get($labels, 'com.docker.compose.service');

            return ! ($serviceName && $excludedContainers->contains($serviceName));
        });

        // If all containers are excluded, calculate status from excluded containers
        // but mark it with :excluded to indicate monitoring is disabled
        if ($relevantContainers->isEmpty()) {
            return $this->calculateExcludedStatus($containers, $excludedContainers);
        }

        // Use ContainerStatusAggregator service for state machine logic
        $aggregator = new ContainerStatusAggregator;

        return $aggregator->aggregateFromContainers($relevantContainers);
    }
}
