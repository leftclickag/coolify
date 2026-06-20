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

#[Name('create_scheduled_task')]
#[Description('Create a new scheduled task (cron job) for an application or service.')]
class CreateScheduledTask extends Tool
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
        $name = $request->get('name');
        $command = $request->get('command');
        $frequency = $request->get('frequency');

        if (! in_array($resourceType, ['application', 'service'], true)) {
            return Response::error('resource must be one of: application, service.');
        }

        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        if (! is_string($name) || trim($name) === '') {
            return Response::error('name argument is required.');
        }

        if (! is_string($command) || trim($command) === '') {
            return Response::error('command argument is required.');
        }

        if (! is_string($frequency) || trim($frequency) === '') {
            return Response::error('frequency argument is required (cron expression e.g. "0 * * * *").');
        }

        $resource = $resourceType === 'application'
            ? Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first()
            : Service::whereRelation('environment.project.team', 'id', $teamId)->where('uuid', $uuid)->first();

        if (! $resource) {
            return Response::error(ucfirst($resourceType)." [{$uuid}] not found.");
        }

        $task = ScheduledTask::create([
            'name' => trim($name),
            'command' => trim($command),
            'frequency' => trim($frequency),
            'container' => $request->get('container'),
            'team_id' => $teamId,
            $resourceType === 'application' ? 'application_id' : 'service_id' => $resource->id,
        ]);

        return $this->respond(
            [
                'uuid' => $task->uuid,
                'name' => $task->name,
                'message' => 'Scheduled task created.',
            ],
            [
                ['tool' => 'list_scheduled_tasks', 'args' => ['resource' => $resourceType, 'uuid' => $uuid], 'hint' => 'All tasks for this resource'],
            ],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'resource' => $schema->string()->description('Resource type: application or service.')->required(),
            'uuid' => $schema->string()->description('UUID of the resource.')->required(),
            'name' => $schema->string()->description('Name for the scheduled task.')->required(),
            'command' => $schema->string()->description('Shell command to execute.')->required(),
            'frequency' => $schema->string()->description('Cron expression (e.g. "0 * * * *" for hourly).')->required(),
            'container' => $schema->string()->description('Optional container name to run the command in.'),
        ];
    }
}
