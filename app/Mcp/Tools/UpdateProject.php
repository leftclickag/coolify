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

#[Name('update_project')]
#[Description('Update a project name or description.')]
class UpdateProject extends Tool
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

        $uuid = $request->get('uuid');
        if (! is_string($uuid) || $uuid === '') {
            return Response::error('uuid argument is required.');
        }

        $project = Project::whereTeamId($teamId)->whereUuid($uuid)->first();
        if (! $project) {
            return Response::error("Project [{$uuid}] not found.");
        }

        $name = $request->get('name');
        $description = $request->get('description');

        if ($name === null && $description === null) {
            return Response::error('At least one of name or description must be provided.');
        }

        if ($name !== null) {
            if (! is_string($name) || trim($name) === '') {
                return Response::error('name must be a non-empty string.');
            }
            $project->name = trim($name);
        }

        if ($description !== null) {
            $project->description = $description;
        }

        $project->save();

        return $this->respond([
            'uuid' => $project->uuid,
            'name' => $project->name,
            'description' => $project->description,
            'message' => 'Project updated.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('Project UUID.')->required(),
            'name' => $schema->string()->description('New project name.'),
            'description' => $schema->string()->description('New project description.'),
        ];
    }
}
