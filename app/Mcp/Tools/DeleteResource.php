<?php

namespace App\Mcp\Tools;

use App\Jobs\DeleteResourceJob;
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

#[Name('delete_resource')]
#[Description('Delete an application, service, or database. This is irreversible. By default deletes volumes, networks, and configurations.')]
class DeleteResource extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'write')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $resourceType = $request->get('resource');
        $uuid = $request->get('uuid');

        if (! in_array($resourceType, ['application', 'service', 'database'], true)) {
            return Response::error('resource must be one of: application, service, database.');
        }

        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $resource = $this->findResource($resourceType, $uuid, $teamId);
        if (! $resource) {
            return Response::error(ucfirst($resourceType)." [{$uuid}] not found.");
        }

        $deleteVolumes = (bool) ($request->get('delete_volumes') ?? true);
        $deleteConfigurations = (bool) ($request->get('delete_configurations') ?? true);
        $dockerCleanup = (bool) ($request->get('docker_cleanup') ?? true);

        DeleteResourceJob::dispatch(
            resource: $resource,
            deleteVolumes: $deleteVolumes,
            deleteConnectedNetworks: true,
            deleteConfigurations: $deleteConfigurations,
            dockerCleanup: $dockerCleanup,
        );

        return $this->respond([
            'message' => ucfirst($resourceType).' deletion dispatched.',
            'resource' => $uuid,
        ]);
    }

    private function findResource(string $type, string $uuid, int $teamId): mixed
    {
        return match ($type) {
            'application' => Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first(),
            'service' => Service::whereRelation('environment.project.team', 'id', $teamId)->where('uuid', $uuid)->first(),
            'database' => queryDatabaseByUuidWithinTeam($uuid, $teamId),
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Resource type: application, service, or database.')->required(),
            'uuid' => $schema->string()->description('UUID of the resource to delete.')->required(),
            'delete_volumes' => $schema->boolean()->description('Delete associated Docker volumes (default true).'),
            'delete_configurations' => $schema->boolean()->description('Delete configuration files (default true).'),
            'docker_cleanup' => $schema->boolean()->description('Run Docker cleanup after deletion (default true).'),
        ];
    }
}
