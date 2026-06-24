<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_logs')]
#[Description('Get runtime container logs for an application or service. Use get_deployment for deployment/build logs.')]
class GetLogs extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $resourceType = $request->get('resource');
        $uuid = $request->get('uuid');

        if (! in_array($resourceType, ['application', 'service'], true)) {
            return Response::error('resource must be one of: application, service.');
        }

        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $lines = min(500, max(1, (int) ($request->get('lines') ?? 100)));

        if ($resourceType === 'application') {
            return $this->getApplicationLogs($teamId, $uuid, $lines);
        }

        return $this->getServiceLogs($teamId, $uuid, $lines);
    }

    private function getApplicationLogs(int $teamId, string $uuid, int $lines): Response
    {
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return Response::error("Application [{$uuid}] not found.");
        }

        $server = $application->destination->server;
        $containers = getCurrentApplicationContainerStatus($server, $application->id);

        if ($containers->isEmpty()) {
            return $this->respond([
                'logs' => '',
                'container' => null,
                'message' => 'No running containers found.',
            ]);
        }

        $container = $containers->first();
        $containerName = data_get($container, 'Names', data_get($container, 'ID'));
        $logs = getContainerLogs($server, data_get($container, 'ID', $containerName), $lines);

        return $this->respond([
            'logs' => $logs,
            'container' => $containerName,
        ]);
    }

    private function getServiceLogs(int $teamId, string $uuid, int $lines): Response
    {
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)
            ->where('uuid', $uuid)
            ->first();

        if (! $service) {
            return Response::error("Service [{$uuid}] not found.");
        }

        $allLogs = [];
        $server = $service->destination->server;

        // Service stack containers (apps + databases, including scaled replicas) all
        // carry the coolify.serviceId label; the per-application applicationId label
        // does not exist on them, so we query by the service id directly.
        $containers = getCurrentServiceContainerStatus($server, $service->id);
        foreach ($containers as $container) {
            $containerName = data_get($container, 'Names', data_get($container, 'ID'));
            $logs = getContainerLogs($server, data_get($container, 'ID', $containerName), $lines);
            $allLogs[] = [
                'container' => $containerName,
                'logs' => $logs,
            ];
        }

        return $this->respond([
            'containers' => $allLogs,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Resource type: application or service.')->required(),
            'uuid' => $schema->string()->description('UUID of the resource.')->required(),
            'lines' => $schema->integer()->description('Number of log lines to return (default 100, max 500).'),
        ];
    }
}
