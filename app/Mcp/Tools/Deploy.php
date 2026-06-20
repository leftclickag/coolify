<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Visus\Cuid2\Cuid2;

#[Name('deploy')]
#[Description('Trigger a new deployment for an application. Returns the deployment UUID to track progress with get_deployment.')]
class Deploy extends Tool
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

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $application = Application::ownedByCurrentTeamAPI($teamId)->where('uuid', $uuid)->first();
        if (! $application) {
            return Response::error("Application [{$uuid}] not found.");
        }

        $forceRebuild = (bool) ($request->get('force') ?? false);
        $deploymentUuid = new Cuid2;

        $result = queue_application_deployment(
            $application,
            $deploymentUuid->toString(),
            force_rebuild: $forceRebuild,
            is_api: true,
        );

        if (data_get($result, 'status') === 'queue_full') {
            return Response::error(data_get($result, 'message', 'Deployment queue is full.'));
        }

        return $this->respond(
            [
                'deployment_uuid' => $deploymentUuid->toString(),
                'message' => 'Deployment queued.',
            ],
            [
                ['tool' => 'get_deployment', 'args' => ['uuid' => $deploymentUuid->toString()], 'hint' => 'Track deployment progress'],
            ],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Application UUID.')->required(),
            'force' => $schema->boolean()->description('Force a full rebuild from scratch (default false).'),
        ];
    }
}
