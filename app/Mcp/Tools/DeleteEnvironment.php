<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('delete_environment')]
#[Description('Delete an environment from a project. The environment must be empty (no resources).')]
class DeleteEnvironment extends Tool
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

        $projectUuid = $request->get('project_uuid');
        $environmentName = $request->get('environment_name');

        if (! is_string($projectUuid) || $projectUuid === '') {
            return Response::error('project_uuid argument is required.');
        }

        if (! is_string($environmentName) || $environmentName === '') {
            return Response::error('environment_name argument is required.');
        }

        $project = Project::whereTeamId($teamId)->whereUuid($projectUuid)->first();
        if (! $project) {
            return Response::error("Project [{$projectUuid}] not found.");
        }

        $environment = $project->environments()->where('name', $environmentName)->first();
        if (! $environment) {
            return Response::error("Environment [{$environmentName}] not found in project [{$projectUuid}].");
        }

        if (! $environment->isEmpty()) {
            return Response::error("Environment [{$environmentName}] still has resources. Delete all resources in this environment first.");
        }

        $environment->delete();

        return $this->respond([
            'message' => "Environment [{$environmentName}] deleted.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_uuid' => $schema->string()->description('UUID of the project.')->required(),
            'environment_name' => $schema->string()->description('Name of the environment to delete.')->required(),
        ];
    }
}
