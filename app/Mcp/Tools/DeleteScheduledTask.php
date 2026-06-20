<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\ScheduledTask;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('delete_scheduled_task')]
#[Description('Delete a scheduled task by its UUID.')]
class DeleteScheduledTask extends Tool
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

        $taskUuid = $request->get('task_uuid');
        $resourceType = $request->get('resource');
        $resourceUuid = $request->get('uuid');

        if (! is_string($taskUuid) || $taskUuid === '') {
            return Response::error('task_uuid argument is required.');
        }

        if (! in_array($resourceType, ['application', 'service'], true)) {
            return Response::error('resource must be one of: application, service.');
        }

        if (! is_string($resourceUuid) || $resourceUuid === '') {
            return Response::error('uuid argument is required.');
        }

        $resource = $resourceType === 'application'
            ? Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $resourceUuid)->first()
            : Service::whereRelation('environment.project.team', 'id', $teamId)->where('uuid', $resourceUuid)->first();

        if (! $resource) {
            return Response::error(ucfirst($resourceType)." [{$resourceUuid}] not found.");
        }

        $task = ScheduledTask::where('uuid', $taskUuid)
            ->where($resourceType === 'application' ? 'application_id' : 'service_id', $resource->id)
            ->first();

        if (! $task) {
            return Response::error("Scheduled task [{$taskUuid}] not found.");
        }

        $taskName = $task->name;
        $task->delete();

        return $this->respond([
            'message' => "Scheduled task [{$taskName}] deleted.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'task_uuid' => $schema->string()->description('UUID of the scheduled task to delete (from list_scheduled_tasks).')->required(),
            'resource' => $schema->string()->description('Resource type: application or service.')->required(),
            'uuid' => $schema->string()->description('UUID of the resource that owns the task.')->required(),
        ];
    }
}
