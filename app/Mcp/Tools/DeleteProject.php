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

#[Name('delete_project')]
#[Description('Delete an empty project. Projects with resources must have all resources deleted first.')]
class DeleteProject extends Tool
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

        $resourceCount = $project->applications()->count()
            + $project->services()->count()
            + $project->databases()->count();

        if ($resourceCount > 0) {
            return Response::error("Project [{$uuid}] has {$resourceCount} resource(s). Delete all resources first.");
        }

        $projectName = $project->name;
        $project->delete();

        return $this->respond([
            'message' => "Project [{$projectName}] deleted.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('UUID of the project to delete.')->required(),
        ];
    }
}
