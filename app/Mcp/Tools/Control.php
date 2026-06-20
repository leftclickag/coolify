<?php

namespace App\Mcp\Tools;

use App\Actions\Application\StopApplication;
use App\Actions\Database\RestartDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Database\StopDatabase;
use App\Actions\Service\RestartService;
use App\Actions\Service\StartService;
use App\Actions\Service\StopService;
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
use Visus\Cuid2\Cuid2;

#[Name('control')]
#[Description('Start, stop, or restart an application, service, or database.')]
class Control extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'deploy')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $resourceType = $request->get('resource');
        $action = $request->get('action');
        $uuid = $request->get('uuid');

        if (! in_array($resourceType, ['application', 'service', 'database'], true)) {
            return Response::error('resource must be one of: application, service, database.');
        }

        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            return Response::error('action must be one of: start, stop, restart.');
        }

        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        return match ($resourceType) {
            'application' => $this->controlApplication($teamId, $uuid, $action),
            'service' => $this->controlService($teamId, $uuid, $action),
            'database' => $this->controlDatabase($teamId, $uuid, $action),
        };
    }

    private function controlApplication(int $teamId, string $uuid, string $action): Response
    {
        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return Response::error("Application [{$uuid}] not found.");
        }

        if ($action === 'stop') {
            StopApplication::dispatch($application, false, false);

            return $this->respond(['message' => 'Stop dispatched.', 'resource' => $application->uuid]);
        }

        $deploymentUuid = new Cuid2;
        $result = queue_application_deployment(
            $application,
            $deploymentUuid->toString(),
            restart_only: $action === 'restart',
            is_api: true,
        );

        if (data_get($result, 'status') === 'queue_full') {
            return Response::error(data_get($result, 'message', 'Deployment queue is full.'));
        }

        return $this->respond([
            'message' => $action === 'restart' ? 'Restart dispatched.' : 'Start dispatched.',
            'resource' => $application->uuid,
            'deployment_uuid' => $deploymentUuid->toString(),
        ]);
    }

    private function controlService(int $teamId, string $uuid, string $action): Response
    {
        $service = Service::whereRelation('environment.project.team', 'id', $teamId)
            ->where('uuid', $uuid)
            ->first();

        if (! $service) {
            return Response::error("Service [{$uuid}] not found.");
        }

        match ($action) {
            'start' => StartService::dispatch($service),
            'stop' => StopService::dispatch($service, false),
            'restart' => RestartService::dispatch($service),
        };

        return $this->respond([
            'message' => ucfirst($action).' dispatched.',
            'resource' => $service->uuid,
        ]);
    }

    private function controlDatabase(int $teamId, string $uuid, string $action): Response
    {
        $database = queryDatabaseByUuidWithinTeam($uuid, $teamId);
        if (! $database) {
            return Response::error("Database [{$uuid}] not found.");
        }

        match ($action) {
            'start' => StartDatabase::dispatch($database),
            'stop' => StopDatabase::dispatch($database, false),
            'restart' => RestartDatabase::dispatch($database),
        };

        return $this->respond([
            'message' => ucfirst($action).' dispatched.',
            'resource' => $database->uuid,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Resource type: application, service, or database.')->required(),
            'action' => $schema->string()->description('Action: start, stop, or restart.')->required(),
            'uuid' => $schema->string()->description('UUID of the resource.')->required(),
        ];
    }
}
