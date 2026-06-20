<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_environment')]
#[Description('Create a new environment in a project (e.g. staging, production, development).')]
class CreateEnvironment extends Tool
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
        $name = $request->get('name');

        if (! is_string($projectUuid) || $projectUuid === '') {
            return Response::error('project_uuid argument is required.');
        }

        if (! is_string($name) || trim($name) === '') {
            return Response::error('name argument is required.');
        }

        $project = Project::whereTeamId($teamId)->whereUuid($projectUuid)->first();
        if (! $project) {
            return Response::error("Project [{$projectUuid}] not found.");
        }

        $existing = $project->environments()->where('name', trim($name))->first();
        if ($existing) {
            return Response::error("Environment [{$name}] already exists in project [{$projectUuid}].");
        }

        $environment = Environment::create([
            'name' => trim($name),
            'project_id' => $project->id,
        ]);

        return $this->respond([
            'uuid' => $environment->uuid,
            'name' => $environment->name,
            'message' => 'Environment created.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'project_uuid' => $schema->string()->description('UUID of the project.')->required(),
            'name' => $schema->string()->description('Environment name (e.g. production, staging, development).')->required(),
        ];
    }
}
