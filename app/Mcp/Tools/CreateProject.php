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

#[Name('create_project')]
#[Description('Create a new project to organize applications, services, and databases.')]
class CreateProject extends Tool
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

        $name = $request->get('name');
        if (! is_string($name) || trim($name) === '') {
            return Response::error('name argument is required.');
        }

        $description = $request->get('description');

        $project = Project::create([
            'name' => trim($name),
            'description' => $description,
            'team_id' => $teamId,
        ]);

        return $this->respond(
            [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'message' => 'Project created.',
            ],
            [
                ['tool' => 'list_projects', 'args' => [], 'hint' => 'All projects'],
            ],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Project name.')->required(),
            'description' => $schema->string()->description('Optional project description.'),
        ];
    }
}
