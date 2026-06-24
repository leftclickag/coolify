<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Service;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('delete_env')]
#[Description('Delete an environment variable by its UUID. Get env UUIDs from list_envs.')]
class DeleteEnv extends Tool
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

        $env = EnvironmentVariable::where('uuid', $uuid)->first();
        if (! $env) {
            return Response::error("Environment variable [{$uuid}] not found.");
        }

        if (! $this->envBelongsToTeam($env, $teamId)) {
            return Response::error("Environment variable [{$uuid}] not found.");
        }

        $key = $env->key;
        $env->delete();

        return $this->respond([
            'message' => 'Environment variable deleted.',
            'key' => $key,
        ]);
    }

    private function envBelongsToTeam(EnvironmentVariable $env, int $teamId): bool
    {
        $resource = $env->resourceable;
        if (! $resource) {
            return false;
        }

        if ($resource instanceof Application) {
            return Application::ownedByCurrentTeamAPI($teamId)
                ->where('id', $resource->id)
                ->exists();
        }

        if ($resource instanceof Service) {
            return Service::whereRelation('environment.project.team', 'id', $teamId)
                ->where('id', $resource->id)
                ->exists();
        }

        // Standalone databases: team() returns the model or null (not a builder)
        if (method_exists($resource, 'team')) {
            $team = $resource->team();

            return $team && $team->id === $teamId;
        }

        return false;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()->description('UUID of the environment variable to delete (from list_envs).')->required(),
        ];
    }
}
