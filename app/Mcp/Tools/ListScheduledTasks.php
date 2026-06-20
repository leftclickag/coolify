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

#[Name('list_scheduled_tasks')]
#[Description('List scheduled tasks (cron jobs) for an application or service.')]
class ListScheduledTasks extends Tool
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

        $resource = $resourceType === 'application'
            ? Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first()
            : Service::whereRelation('environment.project.team', 'id', $teamId)->where('uuid', $uuid)->first();

        if (! $resource) {
            return Response::error(ucfirst($resourceType)." [{$uuid}] not found.");
        }

        $tasks = $resource->scheduled_tasks
            ->map(fn ($task) => [
                'uuid' => $task->uuid,
                'name' => $task->name,
                'command' => $task->command,
                'frequency' => $task->frequency,
                'container' => $task->container,
                'enabled' => (bool) $task->enabled,
                'timeout' => $task->timeout,
                'created_at' => $task->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        return $this->respond($tasks);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Resource type: application or service.')->required(),
            'uuid' => $schema->string()->description('UUID of the resource.')->required(),
        ];
    }
}
